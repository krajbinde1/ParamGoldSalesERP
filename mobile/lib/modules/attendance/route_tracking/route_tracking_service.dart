import 'dart:async';
import 'dart:io';

import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../../core/storage/session_store.dart';
import '../api/attendance_api_service.dart';
import '../models/attendance.dart';
import 'models/route_point.dart';
import 'route_capture_rules.dart';
import 'route_point_api.dart';
import 'route_point_store.dart';
import 'route_point_sync.dart';
import 'route_tracking_config.dart';
import 'route_tracking_foreground.dart';
import 'route_tracking_log.dart';
import 'route_tracking_permissions.dart';

class RouteTrackingService {
  RouteTrackingService._();

  static final RouteTrackingService instance = RouteTrackingService._();

  RoutePointStore? _store;
  RoutePointSync? _sync;
  AttendanceApiService? _attendanceApi;
  StreamSubscription<Position>? _positionSubscription;
  bool _storeReady = false;
  bool _runtimeDisabledCleanupDone = false;
  bool _handlingPosition = false;
  bool _taskDataCallbackBound = false;

  static const _activeStatus = 'Route Tracking Active';
  static const _stoppedStatus = 'Route tracking stopped';

  String statusMessage = _stoppedStatus;
  RouteTrackingUiStatus uiStatus = RouteTrackingUiStatus.empty;

  /// Clears active session flags when runtime is disabled.
  Future<void> disableRuntimeCleanup() async {
    if (routeTrackingRuntimeEnabled || _runtimeDisabledCleanupDone) return;
    _runtimeDisabledCleanupDone = true;
    statusMessage = '';
    uiStatus = RouteTrackingUiStatus.empty;

    try {
      await _cancelLocalStream();
      await RouteTrackingForeground.stop();
      final prefs = await SharedPreferences.getInstance();
      await RoutePointStore(prefs).clearSession();
      routeTrackingLog('Route tracking runtime disabled: session cleared');
    } catch (error, stackTrace) {
      routeTrackingLog(
        'Route tracking disable cleanup failed: $error\n$stackTrace',
      );
    }
  }

  Future<void> ensureStoreReady() async {
    if (!routeTrackingRuntimeEnabled) {
      await disableRuntimeCleanup();
      return;
    }
    if (_storeReady) return;
    await RouteTrackingForeground.init().timeout(const Duration(seconds: 3));
    _bindTaskDataCallback();
    final prefs = await SharedPreferences.getInstance().timeout(
      const Duration(seconds: 3),
    );
    _store = RoutePointStore(prefs);
    final api = await RoutePointApi.create().timeout(const Duration(seconds: 5));
    _sync = RoutePointSync(
      _store!,
      api,
      onInvalidAttendance: _handleInvalidAttendance,
    );
    statusMessage = _store!.session?.isActive == true
        ? _activeStatus
        : (_store!.session?.statusMessage ?? _stoppedStatus);
    // Avoid GPS/permission platform calls during store bootstrap (ANR risk).
    uiStatus = RouteTrackingUiStatus(
      message: statusMessage,
      isActive: _store!.session?.isActive == true,
      lastLocationAt: _store!.session?.lastRecordedAt,
      pendingSyncCount: _store!.pendingPoints().length,
      gpsStatus: _store!.session?.gpsStatus ?? 'unknown',
      permissionStatus: _store!.session?.permissionStatus ?? 'unknown',
    );
    _storeReady = true;
  }

  void _bindTaskDataCallback() {
    if (_taskDataCallbackBound) return;
    FlutterForegroundTask.addTaskDataCallback(_onTaskData);
    _taskDataCallbackBound = true;
  }

  void _onTaskData(Object data) {
    if (data is! Map) return;
    if (data['type'] != 'route_tracking_status') return;
    statusMessage = data['message']?.toString() ?? statusMessage;
    uiStatus = RouteTrackingUiStatus(
      message: statusMessage,
      isActive: data['is_active'] == true,
      lastLocationAt: data['last_recorded_at']?.toString(),
      pendingSyncCount:
          int.tryParse('${data['pending_sync_count'] ?? 0}') ?? 0,
      gpsStatus: data['gps_status']?.toString() ?? 'unknown',
      permissionStatus: data['permission_status']?.toString() ?? 'unknown',
    );
  }

  Future<void> _ensureReady() async {
    await ensureStoreReady();
  }

  Future<int?> _resolveEmployeeId() async {
    final session = await SessionStore().read();
    return session?.user.employeeId ??
        session?.employee.id ??
        _store?.session?.employeeId;
  }

  Future<Attendance?> _fetchServerToday() async {
    try {
      _attendanceApi ??= await AttendanceApiService.create();
      return await _attendanceApi!.today();
    } catch (error) {
      routeTrackingLog('Failed to fetch server today attendance: $error');
      return null;
    }
  }

  void _logAttendanceContext({
    required String stage,
    Attendance? attendance,
    int? uploadedAttendanceId,
  }) {
    routeTrackingLog(
      '$stage: activeAttendanceId=$activeAttendanceId '
      'uploadedAttendanceId=$uploadedAttendanceId '
      'backendAttendanceId=${attendance?.id} '
      'punchOut=${attendance?.punchOut} '
      'employeeId=${attendance?.employeeId}',
    );
  }

  bool _isActiveServerAttendance(Attendance? attendance) {
    return attendance != null &&
        attendance.id != null &&
        attendance.punchIn != null &&
        attendance.punchOut == null;
  }

  Future<void> _cancelLocalStream() async {
    final subscription = _positionSubscription;
    _positionSubscription = null;
    if (subscription != null) {
      await subscription.cancel();
      routeTrackingLog('Local position stream cancelled');
    }
  }

  /// iOS / fallback stream when FGS package is limited.
  Future<void> _startLocalStreamFallback() async {
    if (_positionSubscription != null) return;
    final settings = RouteCaptureRules.streamLocationSettings(
      withGeolocatorNotification: Platform.isAndroid,
    );
    _positionSubscription =
        Geolocator.getPositionStream(locationSettings: settings).listen(
          _onPositionUpdate,
          onError: (Object error, StackTrace stackTrace) {
            routeTrackingLog('Position stream error: $error\n$stackTrace');
          },
          cancelOnError: false,
        );
    routeTrackingLog('Local position stream started');
  }

  Future<void> _onPositionUpdate(Position position) async {
    if (_handlingPosition || !routeTrackingRuntimeEnabled) return;
    _handlingPosition = true;
    try {
      await _ensureReady();
      final store = _store;
      if (store == null || store.session?.isActive != true) return;

      final point = await RouteCaptureRules.captureFromPosition(
        store: store,
        position: position,
      );
      if (point != null) {
        await _syncValidated();
        await _refreshUiStatus();
      }
    } catch (error, stackTrace) {
      routeTrackingLog('Position update handling failed: $error\n$stackTrace');
    } finally {
      _handlingPosition = false;
    }
  }

  Future<void> _startTrackingEngines() async {
    final started = await RouteTrackingForeground.start();
    if (!started || Platform.isIOS) {
      await _startLocalStreamFallback();
    } else {
      // Android FGS owns GPS; keep local stream off to avoid duplicate points.
      await _cancelLocalStream();
    }
  }

  Future<void> _handleInvalidAttendance(
    int attendanceId,
    String message,
  ) async {
    await _ensureReady();
    routeTrackingLog(
      'Handling invalid attendance: attendanceId=$attendanceId message=$message',
    );
    await _store!.clearPointsForAttendance(attendanceId);
    await _cancelLocalStream();
    await RouteTrackingForeground.stop();
    await _store!.clearSession();
    statusMessage =
        'Route tracking paused. Please punch in again to resume tracking.';
    await _refreshUiStatus();
    routeTrackingLog('Route tracking invalidated: $statusMessage');
  }

  Future<void> _syncValidated({Attendance? serverAttendance}) async {
    await _ensureReady();
    final attendance = serverAttendance ?? await _fetchServerToday();
    _logAttendanceContext(stage: 'Sync validation', attendance: attendance);

    if (!_isActiveServerAttendance(attendance)) {
      // Still flush any queued points for a closed attendance.
      final staleId = activeAttendanceId ?? attendance?.id;
      await _sync!.syncPending(
        activeAttendanceId: staleId,
        allowClosedAttendance: true,
      );
      return;
    }

    final backendId = attendance!.id!;
    final session = _store!.session;
    if (session?.isActive == true && session!.attendanceId != backendId) {
      routeTrackingLog(
        'Replacing stale active attendance_id=${session.attendanceId} '
        'with backendAttendanceId=$backendId',
      );
      await _store!.clearPointsForAttendance(session.attendanceId);
      await _store!.saveSession(session.copyWith(attendanceId: backendId));
    }

    await _store!.retainOnlyAttendance(backendId);
    await _sync!.syncPending(activeAttendanceId: backendId);
  }

  Future<void> _refreshUiStatus() async {
    await _ensureReady();
    final session = _store?.session;
    final pending = _store?.pendingPoints().length ?? 0;
    String gps = session?.gpsStatus ?? 'unknown';
    String permission = session?.permissionStatus ?? 'unknown';
    try {
      gps = await RouteTrackingPermissions.currentGpsLabel().timeout(
        const Duration(seconds: 2),
      );
      permission = await RouteTrackingPermissions.currentPermissionLabel()
          .timeout(const Duration(seconds: 3));
    } catch (_) {
      // Keep cached labels — never block UI on GPS/permission queries.
    }
    final active = session?.isActive == true;
    statusMessage = active
        ? _activeStatus
        : (session?.statusMessage ?? statusMessage);
    uiStatus = RouteTrackingUiStatus(
      message: statusMessage,
      isActive: active,
      lastLocationAt: session?.lastRecordedAt,
      pendingSyncCount: pending,
      gpsStatus: gps,
      permissionStatus: permission,
    );
  }

  Future<RouteTrackingUiStatus> refreshStatus() async {
    await _refreshUiStatus();
    return uiStatus;
  }

  bool get isActive =>
      routeTrackingRuntimeEnabled && _store?.session?.isActive == true;

  int? get activeAttendanceId {
    if (!routeTrackingRuntimeEnabled) return null;
    final id = _store?.session?.attendanceId;
    return id != null && id > 0 ? id : null;
  }

  Future<void> resumeActiveSession(
    int attendanceId, {
    int? employeeId,
  }) async {
    if (!routeTrackingRuntimeEnabled) {
      await disableRuntimeCleanup();
      return;
    }
    try {
      await _ensureReady();
      if (_store == null) return;

      final store = _store!;
      final session = store.session;
      final resolvedEmployeeId = employeeId ?? await _resolveEmployeeId();
      final permissionLabel =
          await RouteTrackingPermissions.currentPermissionLabel();
      final gpsLabel = await RouteTrackingPermissions.currentGpsLabel();

      if (session?.attendanceId != null &&
          session!.attendanceId != attendanceId) {
        await store.clearPointsForAttendance(session.attendanceId);
      }

      await store.saveSession(
        RouteTrackingSession(
          attendanceId: attendanceId,
          employeeId: resolvedEmployeeId,
          isActive: true,
          lastLatitude: session?.attendanceId == attendanceId
              ? session?.lastLatitude
              : null,
          lastLongitude: session?.attendanceId == attendanceId
              ? session?.lastLongitude
              : null,
          lastRecordedAt: session?.attendanceId == attendanceId
              ? session?.lastRecordedAt
              : null,
          statusMessage: _activeStatus,
          gpsStatus: gpsLabel,
          permissionStatus: permissionLabel,
        ),
      );
      statusMessage = _activeStatus;
      routeTrackingLog('Resumed active session for attendance=$attendanceId');

      await _startTrackingEngines();
      await _refreshUiStatus();
    } catch (error, stackTrace) {
      routeTrackingLog('Resume service failed: $error\n$stackTrace');
    }
  }

  Future<String> start(int attendanceId, {int? employeeId}) async {
    if (!routeTrackingRuntimeEnabled) {
      await disableRuntimeCleanup();
      return '';
    }
    try {
      await ensureStoreReady();
      final store = _store!;
      final resolvedEmployeeId = employeeId ?? await _resolveEmployeeId();

      final current = store.session;
      if (current?.isActive == true && current!.attendanceId == attendanceId) {
        statusMessage = _activeStatus;
        routeTrackingLog(
          'Route tracking already active for attendance=$attendanceId',
        );
        await _startTrackingEngines();
        await store.retainOnlyAttendance(attendanceId);
        await _refreshUiStatus();
        return statusMessage;
      }
      if (current?.isActive == true) {
        await stop(captureFinalPoint: false);
      }

      final permission = await RouteTrackingPermissions.ensureForTracking();
      if (!permission.granted) {
        statusMessage = permission.message;
        await store.saveSession(
          RouteTrackingSession(
            attendanceId: attendanceId,
            employeeId: resolvedEmployeeId,
            isActive: false,
            statusMessage: permission.message,
            permissionStatus: permission.permissionStatus,
          ),
        );
        await _refreshUiStatus();
        routeTrackingLog('Route tracking not started: ${permission.message}');
        return permission.message;
      }

      for (final staleId
          in store
              .allPoints()
              .map((point) => point.attendanceId)
              .where((id) => id != attendanceId)
              .toSet()) {
        await store.clearPointsForAttendance(staleId);
      }

      await store.saveSession(
        RouteTrackingSession(
          attendanceId: attendanceId,
          employeeId: resolvedEmployeeId,
          isActive: true,
          statusMessage: _activeStatus,
          gpsStatus: await RouteTrackingPermissions.currentGpsLabel(),
          permissionStatus: permission.permissionStatus,
        ),
      );
      await store.retainOnlyAttendance(attendanceId);
      statusMessage = _activeStatus;
      routeTrackingLog(
        'Route tracking started: activeAttendanceId=$attendanceId',
      );

      await _startTrackingEngines();
      // Seed first point immediately after punch-in.
      await RouteCaptureRules.captureIfNeeded(store: store);
      await _syncValidated(
        serverAttendance: Attendance(
          id: attendanceId,
          employeeId: resolvedEmployeeId,
          date: DateTime.now(),
          punchIn: DateTime.now(),
        ),
      );
      await _refreshUiStatus();

      return statusMessage;
    } catch (error, stackTrace) {
      routeTrackingLog('Route tracking start failed: $error\n$stackTrace');
      statusMessage = 'Route tracking unavailable. Attendance saved.';
      await _refreshUiStatus();
      return statusMessage;
    }
  }

  Future<void> captureAndSyncBeforePunchOut() async {
    if (!routeTrackingRuntimeEnabled) return;
    await _ensureReady();
    final session = _store!.session;
    if (session?.isActive != true) return;

    routeTrackingLog(
      'Preparing punch-out sync while attendance still active: '
      'activeAttendanceId=${session!.attendanceId}',
    );

    try {
      final position = await Geolocator.getCurrentPosition(
        locationSettings: RouteCaptureRules.locationSettings(),
      );
      if (RouteCaptureRules.isValidCoordinate(
            position.latitude,
            position.longitude,
          ) &&
          RouteCaptureRules.hasAcceptableAccuracy(position.accuracy)) {
        await RouteCaptureRules.captureFromPosition(
          store: _store!,
          position: position,
        );
      }
    } catch (error) {
      routeTrackingLog('Pre punch-out capture failed: $error');
    }

    await _syncValidated();
    await _refreshUiStatus();
  }

  Future<String> stop({bool captureFinalPoint = true}) async {
    if (!routeTrackingRuntimeEnabled) {
      await disableRuntimeCleanup();
      return '';
    }
    try {
      await _ensureReady();
      final store = _store!;
      final session = store.session;
      final attendanceId = session?.attendanceId;

      routeTrackingLog(
        'Route tracking stop requested (captureFinalPoint=$captureFinalPoint)',
      );

      if (session?.isActive == true &&
          captureFinalPoint &&
          session!.attendanceId > 0) {
        try {
          final position = await Geolocator.getCurrentPosition(
            locationSettings: RouteCaptureRules.locationSettings(),
          );
          if (RouteCaptureRules.isValidCoordinate(
                position.latitude,
                position.longitude,
              ) &&
              RouteCaptureRules.hasAcceptableAccuracy(position.accuracy)) {
            final point = RouteCaptureRules.buildPoint(
              attendanceId: session.attendanceId,
              employeeId: session.employeeId,
              position: position,
              source: routeTrackingSource,
            );
            await store.enqueue(point);
            routeTrackingLog(
              'Final point queued before stop: uuid=${point.localUuid}',
            );
          }
        } catch (error) {
          routeTrackingLog('Final capture before stop failed: $error');
        }
      }

      // Sync while points are still queued; keep queue if offline.
      if (attendanceId != null && attendanceId > 0) {
        try {
          await _sync!.syncPending(
            activeAttendanceId: attendanceId,
            allowClosedAttendance: true,
          );
        } catch (error) {
          routeTrackingLog(
            'Stop sync deferred — points remain queued: $error',
          );
        }
      }

      // Stop FGS only after final point captured and sync attempted/queued.
      await _cancelLocalStream();
      await RouteTrackingForeground.stop();
      await store.clearSession();
      statusMessage = _stoppedStatus;
      await _refreshUiStatus();
      routeTrackingLog('Route tracking stopped');
      return statusMessage;
    } catch (error, stackTrace) {
      routeTrackingLog('Route tracking stop failed: $error\n$stackTrace');
      await _cancelLocalStream();
      await RouteTrackingForeground.stop();
      statusMessage = _stoppedStatus;
      await _refreshUiStatus();
      return statusMessage;
    }
  }

  /// Cold-start housekeeping: never start GPS/FGS here.
  ///
  /// Older builds used `autoRunOnBoot` + server recovery which could ANR the
  /// release APK. Tracking must start only after punch-in.
  Future<void> ensureStoppedOnColdStart() async {
    if (!routeTrackingRuntimeEnabled) {
      await disableRuntimeCleanup();
      return;
    }
    try {
      await RouteTrackingForeground.init().timeout(const Duration(seconds: 3));
      await _cancelLocalStream();
      await RouteTrackingForeground.stop();

      // Clear stale "active" flags so launch never resumes engines.
      final prefs = await SharedPreferences.getInstance().timeout(
        const Duration(seconds: 3),
      );
      final store = RoutePointStore(prefs);
      if (store.session?.isActive == true) {
        await store.saveSession(
          store.session!.copyWith(
            isActive: false,
            statusMessage: _stoppedStatus,
          ),
        );
      }
      statusMessage = _stoppedStatus;
      routeTrackingLog(
        'Cold start: route tracking forced stopped (starts only after punch-in)',
      );
    } catch (error, stackTrace) {
      routeTrackingLog('ensureStoppedOnColdStart failed: $error\n$stackTrace');
    }
  }

  @Deprecated('Use ensureStoppedOnColdStart — launch must not resume tracking')
  Future<void> recoverOnAppStart() async {
    await ensureStoppedOnColdStart();
  }

  Future<void> recoverFromAttendance(Attendance? attendance) async {
    if (!routeTrackingRuntimeEnabled) {
      await disableRuntimeCleanup();
      return;
    }
    try {
      await _ensureReady();
      if (_store == null) return;

      _logAttendanceContext(
        stage: 'Recover from attendance',
        attendance: attendance,
      );

      if (attendance == null) {
        if (isActive) {
          await _cancelLocalStream();
          await RouteTrackingForeground.stop();
          await _store!.clearSession();
          statusMessage = _stoppedStatus;
        }
        await _refreshUiStatus();
        return;
      }

      if (attendance.punchOut != null || !attendance.canPunchOut) {
        await _cancelLocalStream();
        final staleId = activeAttendanceId ?? attendance.id;
        if (staleId != null && staleId > 0) {
          await _sync!.syncPending(
            activeAttendanceId: staleId,
            allowClosedAttendance: true,
          );
        }
        await RouteTrackingForeground.stop();
        await _store!.clearSession();
        statusMessage = _stoppedStatus;
        await _refreshUiStatus();
        return;
      }

      if (attendance.punchIn != null && attendance.id != null) {
        final session = _store!.session;
        if (session?.attendanceId != null &&
            session!.attendanceId != attendance.id) {
          routeTrackingLog(
            'Recover replacing attendance_id=${session.attendanceId} '
            'with backendAttendanceId=${attendance.id}',
          );
          await _store!.clearPointsForAttendance(session.attendanceId);
        }
        await resumeActiveSession(
          attendance.id!,
          employeeId: attendance.employeeId,
        );
        await _syncValidated(serverAttendance: attendance);
        await _refreshUiStatus();
        return;
      }

      if (isActive) {
        await stop(captureFinalPoint: false);
      } else {
        await _cancelLocalStream();
        await RouteTrackingForeground.stop();
        await _store!.clearSession();
        statusMessage = _stoppedStatus;
      }
      await _refreshUiStatus();
    } catch (error, stackTrace) {
      routeTrackingLog('recoverFromAttendance failed: $error\n$stackTrace');
      statusMessage = _stoppedStatus;
      await _refreshUiStatus();
    }
  }

  Future<void> onAppResumed() async {
    if (!routeTrackingRuntimeEnabled) {
      await disableRuntimeCleanup();
      return;
    }
    try {
      // Only soft-sync offline queue. Do not start FGS/GPS from resume.
      await ensureStoreReady().timeout(const Duration(seconds: 5));
      routeTrackingLog('App resumed — soft sync only (no auto engine start)');
      await _sync?.syncPending(allowClosedAttendance: true).timeout(
        const Duration(seconds: 8),
      );
    } catch (error, stackTrace) {
      routeTrackingLog('onAppResumed soft sync failed: $error\n$stackTrace');
    }
  }

  // TEST ONLY - REMOVE BEFORE PRODUCTION
  Future<void> resetForTest({int? deletedAttendanceId}) async {
    if (!routeTrackingRuntimeEnabled) {
      await disableRuntimeCleanup();
      return;
    }
    await _ensureReady();
    final store = _store!;
    final sessionAttendanceId = store.session?.attendanceId;
    final targetId = deletedAttendanceId ?? sessionAttendanceId;

    await _cancelLocalStream();
    await RouteTrackingForeground.stop();

    if (targetId != null && targetId > 0) {
      await store.clearPointsForAttendance(targetId);
    }
    await store.clearSession();
    statusMessage = _stoppedStatus;
    await _refreshUiStatus();
    routeTrackingLog('TEST reset cleared attendance_id=$targetId');
  }
}
