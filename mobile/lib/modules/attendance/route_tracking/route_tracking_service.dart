import 'dart:async';
import 'dart:io';

import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../../core/api/api_config.dart';
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
  bool _recovering = false;

  static const _activeStatus = 'Route Tracking Active';

  String statusMessage = 'No active attendance found';
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
    final sessionActive = _store!.session?.isActive == true;
    statusMessage = sessionActive
        ? _activeStatus
        : (_store!.session?.statusMessage ?? 'No active attendance found');
    uiStatus = RouteTrackingUiStatus(
      message: statusMessage,
      isActive: sessionActive,
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

  Future<bool> _hasAuthToken() async {
    final prefs = await SharedPreferences.getInstance();
    final token =
        prefs.getString('login_token') ?? prefs.getString('token') ?? '';
    if (token.isNotEmpty) return true;
    final secure = await SessionStore().token();
    return secure != null && secure.isNotEmpty;
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

  Future<bool> _startTrackingEngines() async {
    routeTrackingLog('Foreground service start request');
    final started = await RouteTrackingForeground.start();
    if (!started || Platform.isIOS) {
      await _startLocalStreamFallback();
    } else {
      await _cancelLocalStream();
    }
    if (Platform.isAndroid && !started) {
      routeTrackingLog('Foreground service failed to start');
      return false;
    }
    if (started) {
      routeTrackingLog('Foreground service started');
    }
    return started || !Platform.isAndroid;
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

    final current = _store!.session;
    if (current != null) {
      await _store!.saveSession(
        current.copyWith(
          lastSyncedAt: DateTime.now().toUtc().toIso8601String(),
        ),
      );
    }
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

    final sessionActive = session?.isActive == true;
    var serviceRunning = !Platform.isAndroid;
    try {
      if (Platform.isAndroid) {
        serviceRunning = await RouteTrackingForeground.isRunning.timeout(
          const Duration(seconds: 2),
        );
      }
    } catch (_) {
      serviceRunning = false;
    }

    final displayActive = sessionActive && serviceRunning;
    if (displayActive) {
      statusMessage = _activeStatus;
    } else if (sessionActive && !serviceRunning) {
      final hasToken = await _hasAuthToken();
      statusMessage = await RouteTrackingPermissions.diagnoseStoppedReason(
        sessionActive: true,
        serviceRunning: false,
        hasToken: hasToken,
      );
      // Persist reason for reopen without losing active session flag.
      if (session != null) {
        await _store!.saveSession(
          session.copyWith(statusMessage: statusMessage),
        );
      }
    } else {
      statusMessage = session?.statusMessage ?? 'No active attendance found';
    }

    uiStatus = RouteTrackingUiStatus(
      message: statusMessage,
      isActive: displayActive,
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
    DateTime? punchInAt,
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
          punchInAt: punchInAt?.toIso8601String() ??
              session?.punchInAt ??
              DateTime.now().toIso8601String(),
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
          lastSyncedAt: session?.attendanceId == attendanceId
              ? session?.lastSyncedAt
              : null,
          apiBaseUrl: ApiConfig.baseUrl,
          statusMessage: _activeStatus,
          gpsStatus: gpsLabel,
          permissionStatus: permissionLabel,
        ),
      );
      statusMessage = _activeStatus;
      routeTrackingLog(
        'Resumed active session for attendance=$attendanceId',
      );

      final started = await _startTrackingEngines();
      if (!started && Platform.isAndroid) {
        statusMessage = 'Foreground service failed to start';
        await store.saveSession(
          store.session!.copyWith(statusMessage: statusMessage),
        );
      }
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
      final punchInAt = DateTime.now().toIso8601String();

      routeTrackingLog(
        'Punch In success — attendance ID received=$attendanceId',
      );

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
      routeTrackingLog(
        'Location permission status=${permission.permissionStatus}',
      );
      if (!permission.granted) {
        statusMessage = permission.message;
        await store.saveSession(
          RouteTrackingSession(
            attendanceId: attendanceId,
            employeeId: resolvedEmployeeId,
            punchInAt: punchInAt,
            isActive: false,
            apiBaseUrl: ApiConfig.baseUrl,
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
          punchInAt: punchInAt,
          isActive: true,
          apiBaseUrl: ApiConfig.baseUrl,
          statusMessage: _activeStatus,
          gpsStatus: await RouteTrackingPermissions.currentGpsLabel(),
          permissionStatus: permission.permissionStatus,
        ),
      );
      await store.retainOnlyAttendance(attendanceId);

      // Persist API base for native/FGS isolate visibility (release = production).
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('route_tracking_api_base_url', ApiConfig.baseUrl);

      statusMessage = _activeStatus;
      routeTrackingLog(
        'Route tracking started: activeAttendanceId=$attendanceId '
        'apiBaseUrl=${ApiConfig.baseUrl}',
      );

      final started = await _startTrackingEngines();
      if (!started && Platform.isAndroid) {
        statusMessage = 'Foreground service failed to start';
        await store.saveSession(
          store.session!.copyWith(
            isActive: true,
            statusMessage: statusMessage,
          ),
        );
        await _refreshUiStatus();
        return statusMessage;
      }

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

      await _cancelLocalStream();
      await RouteTrackingForeground.stop();
      await store.clearSession();
      statusMessage = 'Punch out complete';
      await _refreshUiStatus();
      routeTrackingLog('Punch Out success — foreground service stopped');
      return statusMessage;
    } catch (error, stackTrace) {
      routeTrackingLog('Route tracking stop failed: $error\n$stackTrace');
      await _cancelLocalStream();
      await RouteTrackingForeground.stop();
      statusMessage = 'Punch out complete';
      await _refreshUiStatus();
      return statusMessage;
    }
  }

  /// Cold start / process death recovery.
  ///
  /// Restores FGS when a punched-in session exists. Never clears an active
  /// session here — that previously showed "Route tracking stopped" after
  /// swipe-away.
  Future<void> recoverOnAppStart() async {
    if (!routeTrackingRuntimeEnabled) {
      await disableRuntimeCleanup();
      return;
    }
    if (_recovering) return;
    _recovering = true;
    try {
      await ensureStoreReady().timeout(const Duration(seconds: 5));
      final local = _store?.session;
      routeTrackingLog(
        'Cold start recover: localActive=${local?.isActive} '
        'attendanceId=${local?.attendanceId}',
      );

      if (local?.isActive == true && (local?.attendanceId ?? 0) > 0) {
        final running = await RouteTrackingForeground.isRunning.timeout(
          const Duration(seconds: 2),
          onTimeout: () => false,
        );
        if (!running) {
          routeTrackingLog(
            'Active attendance found but service not running — restarting FGS',
          );
          await _startTrackingEngines();
        } else {
          routeTrackingLog('Foreground service already running');
        }
        await _refreshUiStatus();
        unawaited(_reconcileWithServerQuietly());
        return;
      }

      // No local active session — check server for unfinished punch-in.
      Attendance? attendance;
      try {
        attendance = await _fetchServerToday().timeout(
          const Duration(seconds: 6),
        );
      } catch (_) {
        attendance = null;
      }

      if (_isActiveServerAttendance(attendance)) {
        routeTrackingLog(
          'Server active punch-in found id=${attendance!.id} — restoring FGS',
        );
        await resumeActiveSession(
          attendance.id!,
          employeeId: attendance.employeeId,
          punchInAt: attendance.punchIn,
        );
        return;
      }

      // Ensure orphan FGS is not left running without attendance.
      final running = await RouteTrackingForeground.isRunning.timeout(
        const Duration(seconds: 2),
        onTimeout: () => false,
      );
      if (running) {
        routeTrackingLog('Stopping orphan FGS — no active attendance');
        await RouteTrackingForeground.stop();
      }
      statusMessage = 'No active attendance found';
      await _refreshUiStatus();
    } catch (error, stackTrace) {
      routeTrackingLog('recoverOnAppStart failed: $error\n$stackTrace');
    } finally {
      _recovering = false;
    }
  }

  Future<void> _reconcileWithServerQuietly() async {
    try {
      final attendance = await _fetchServerToday().timeout(
        const Duration(seconds: 8),
      );
      if (attendance == null) return;
      if (attendance.punchOut != null) {
        routeTrackingLog('Server shows punched out — stopping tracking');
        await stop(captureFinalPoint: false);
        return;
      }
      if (_isActiveServerAttendance(attendance)) {
        final localId = activeAttendanceId;
        if (localId != null && localId != attendance.id) {
          await resumeActiveSession(
            attendance.id!,
            employeeId: attendance.employeeId,
            punchInAt: attendance.punchIn,
          );
        } else {
          await _syncValidated(serverAttendance: attendance);
        }
      }
    } catch (error) {
      routeTrackingLog('Quiet server reconcile failed: $error');
    }
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
        // Keep local active session if present (offline reopen).
        if (isActive) {
          final running = await RouteTrackingForeground.isRunning;
          if (!running) {
            await _startTrackingEngines();
          }
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
        statusMessage = 'No active attendance found';
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
          punchInAt: attendance.punchIn,
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
        statusMessage = 'No active attendance found';
      }
      await _refreshUiStatus();
    } catch (error, stackTrace) {
      routeTrackingLog('recoverFromAttendance failed: $error\n$stackTrace');
      await _refreshUiStatus();
    }
  }

  Future<void> onAppResumed() async {
    if (!routeTrackingRuntimeEnabled) {
      await disableRuntimeCleanup();
      return;
    }
    try {
      await ensureStoreReady().timeout(const Duration(seconds: 5));
      final session = _store?.session;
      if (session?.isActive == true && (session?.attendanceId ?? 0) > 0) {
        final running = await RouteTrackingForeground.isRunning.timeout(
          const Duration(seconds: 2),
          onTimeout: () => false,
        );
        if (!running) {
          routeTrackingLog(
            'App resumed: active attendance but FGS stopped — restarting',
          );
          await _startTrackingEngines();
        }
        await RouteTrackingForeground.requestSync();
        await _sync?.syncPending(allowClosedAttendance: true).timeout(
          const Duration(seconds: 8),
        );
        await _refreshUiStatus();
        return;
      }

      routeTrackingLog('App resumed — soft sync only');
      await _sync?.syncPending(allowClosedAttendance: true).timeout(
        const Duration(seconds: 8),
      );
      await _refreshUiStatus();
    } catch (error, stackTrace) {
      routeTrackingLog('onAppResumed failed: $error\n$stackTrace');
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
    statusMessage = 'No active attendance found';
    await _refreshUiStatus();
    routeTrackingLog('TEST reset cleared attendance_id=$targetId');
  }
}
