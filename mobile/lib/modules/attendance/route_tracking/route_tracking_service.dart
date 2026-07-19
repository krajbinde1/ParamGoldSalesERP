import 'dart:async';

import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/attendance_api_service.dart';
import '../models/attendance.dart';
import 'route_capture_rules.dart';
import 'route_point_api.dart';
import 'route_point_store.dart';
import 'route_point_sync.dart';
import 'route_tracking_config.dart';
import 'route_tracking_log.dart';
import 'route_tracking_permissions.dart';
import 'models/route_point.dart';

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

  static const _activeStatus = 'Route Tracking Active';
  static const _stoppedStatus = 'Route tracking stopped';

  String statusMessage = _stoppedStatus;

  /// Clears active session flags when runtime is disabled.
  Future<void> disableRuntimeCleanup() async {
    if (routeTrackingRuntimeEnabled || _runtimeDisabledCleanupDone) return;
    _runtimeDisabledCleanupDone = true;
    statusMessage = '';

    try {
      await _cancelPositionStream();
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
    final prefs = await SharedPreferences.getInstance();
    _store = RoutePointStore(prefs);
    final api = await RoutePointApi.create();
    _sync = RoutePointSync(
      _store!,
      api,
      onInvalidAttendance: _handleInvalidAttendance,
    );
    statusMessage = _store!.session?.isActive == true
        ? _activeStatus
        : (_store!.session?.statusMessage ?? _stoppedStatus);
    _storeReady = true;
  }

  Future<void> _ensureReady() async {
    await ensureStoreReady();
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

  Future<void> _cancelPositionStream() async {
    final subscription = _positionSubscription;
    _positionSubscription = null;
    if (subscription != null) {
      await subscription.cancel();
      routeTrackingLog('Position stream cancelled');
    }
  }

  Future<void> _startPositionStream() async {
    if (_positionSubscription != null) {
      routeTrackingLog('Position stream already active — skipping duplicate');
      return;
    }

    final settings = RouteCaptureRules.streamLocationSettings();
    _positionSubscription =
        Geolocator.getPositionStream(locationSettings: settings).listen(
          _onPositionUpdate,
          onError: (Object error, StackTrace stackTrace) {
            routeTrackingLog('Position stream error: $error\n$stackTrace');
          },
          cancelOnError: false,
        );
    routeTrackingLog('Position stream started with foreground notification');
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
      }
    } catch (error, stackTrace) {
      routeTrackingLog('Position update handling failed: $error\n$stackTrace');
    } finally {
      _handlingPosition = false;
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
    await _cancelPositionStream();
    await _store!.clearSession();
    statusMessage =
        'Route tracking paused. Please punch in again to resume tracking.';
    routeTrackingLog('Route tracking invalidated: $statusMessage');
  }

  Future<void> _syncValidated({Attendance? serverAttendance}) async {
    await _ensureReady();
    final attendance = serverAttendance ?? await _fetchServerToday();
    _logAttendanceContext(stage: 'Sync validation', attendance: attendance);

    if (!_isActiveServerAttendance(attendance)) {
      final staleId = activeAttendanceId;
      if (staleId != null) {
        await _handleInvalidAttendance(
          staleId,
          'Today\'s attendance is no longer active on the server.',
        );
      }
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

  bool get isActive =>
      routeTrackingRuntimeEnabled && _store?.session?.isActive == true;

  int? get activeAttendanceId {
    if (!routeTrackingRuntimeEnabled) return null;
    final id = _store?.session?.attendanceId;
    return id != null && id > 0 ? id : null;
  }

  Future<void> resumeActiveSession(int attendanceId) async {
    if (!routeTrackingRuntimeEnabled) {
      await disableRuntimeCleanup();
      return;
    }
    try {
      await _ensureReady();
      if (_store == null) return;

      final store = _store!;
      final session = store.session;

      if (session?.attendanceId != null &&
          session!.attendanceId != attendanceId) {
        await store.clearPointsForAttendance(session.attendanceId);
      }

      await store.saveSession(
        RouteTrackingSession(
          attendanceId: attendanceId,
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
        ),
      );
      statusMessage = _activeStatus;
      routeTrackingLog('Resumed active session for attendance=$attendanceId');

      await _startPositionStream();
    } catch (error, stackTrace) {
      routeTrackingLog('Resume service failed: $error\n$stackTrace');
    }
  }

  Future<String> start(int attendanceId) async {
    if (!routeTrackingRuntimeEnabled) {
      await disableRuntimeCleanup();
      return '';
    }
    try {
      await ensureStoreReady();
      final store = _store!;

      final current = store.session;
      if (current?.isActive == true && current!.attendanceId == attendanceId) {
        statusMessage = _activeStatus;
        routeTrackingLog(
          'Route tracking already active for attendance=$attendanceId',
        );
        if (_positionSubscription == null) {
          await _startPositionStream();
        }
        await store.retainOnlyAttendance(attendanceId);
        return statusMessage;
      }
      if (current?.isActive == true) {
        await stop(captureFinalPoint: false);
      }

      final permission = await RouteTrackingPermissions.ensureForTracking();
      if (!permission.granted) {
        statusMessage = permission.message;
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
          isActive: true,
          statusMessage: _activeStatus,
        ),
      );
      await store.retainOnlyAttendance(attendanceId);
      statusMessage = _activeStatus;
      routeTrackingLog(
        'Route tracking started: activeAttendanceId=$attendanceId',
      );

      await _startPositionStream();
      await _syncValidated(
        serverAttendance: Attendance(
          id: attendanceId,
          date: DateTime.now(),
          punchIn: DateTime.now(),
        ),
      );

      return statusMessage;
    } catch (error, stackTrace) {
      routeTrackingLog('Route tracking start failed: $error\n$stackTrace');
      statusMessage = 'Route tracking unavailable. Attendance saved.';
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
        await _sync!.syncPending(activeAttendanceId: attendanceId);
      }

      await _cancelPositionStream();
      await store.clearSession();
      statusMessage = _stoppedStatus;
      routeTrackingLog('Route tracking stopped');
      return statusMessage;
    } catch (error, stackTrace) {
      routeTrackingLog('Route tracking stop failed: $error\n$stackTrace');
      await _cancelPositionStream();
      statusMessage = _stoppedStatus;
      return statusMessage;
    }
  }

  Future<void> recoverOnAppStart() async {
    if (!routeTrackingRuntimeEnabled) {
      await disableRuntimeCleanup();
      return;
    }
    try {
      await _ensureReady();
      if (_store == null) return;

      final attendance = await _fetchServerToday();
      await recoverFromAttendance(attendance);
    } catch (error, stackTrace) {
      routeTrackingLog('recoverOnAppStart failed: $error\n$stackTrace');
      statusMessage = _stoppedStatus;
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
        if (isActive) {
          await _cancelPositionStream();
          await _store!.clearSession();
          statusMessage = _stoppedStatus;
        }
        return;
      }

      if (attendance.punchOut != null || !attendance.canPunchOut) {
        await _cancelPositionStream();
        final staleId = activeAttendanceId ?? attendance.id;
        if (staleId != null && staleId > 0) {
          await _store!.clearPointsForAttendance(staleId);
        }
        await _store!.clearSession();
        statusMessage = _stoppedStatus;
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
        await resumeActiveSession(attendance.id!);
        await _syncValidated(serverAttendance: attendance);
        return;
      }

      if (isActive) {
        await stop(captureFinalPoint: false);
      } else {
        await _cancelPositionStream();
        await _store!.clearSession();
        statusMessage = _stoppedStatus;
      }
    } catch (error, stackTrace) {
      routeTrackingLog('recoverFromAttendance failed: $error\n$stackTrace');
      statusMessage = _stoppedStatus;
    }
  }

  Future<void> onAppResumed() async {
    if (!routeTrackingRuntimeEnabled) {
      await disableRuntimeCleanup();
      return;
    }
    await _ensureReady();
    routeTrackingLog('App resumed — validating session and syncing');
    final attendance = await _fetchServerToday();
    _logAttendanceContext(stage: 'App resumed', attendance: attendance);

    if (!_isActiveServerAttendance(attendance)) {
      final staleId = activeAttendanceId;
      if (staleId != null) {
        await _handleInvalidAttendance(
          staleId,
          'Today\'s attendance is no longer active.',
        );
      }
      return;
    }

    final session = _store!.session;
    if (session?.isActive == true) {
      if (session!.attendanceId != attendance!.id) {
        await _store!.clearPointsForAttendance(session.attendanceId);
        await _store!.saveSession(
          session.copyWith(attendanceId: attendance.id!),
        );
      }
      if (_positionSubscription == null) {
        await _startPositionStream();
      }
    }
    await _syncValidated(serverAttendance: attendance);
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

    await _cancelPositionStream();

    if (targetId != null && targetId > 0) {
      await store.clearPointsForAttendance(targetId);
    }
    await store.clearSession();
    statusMessage = _stoppedStatus;
    routeTrackingLog('TEST reset cleared attendance_id=$targetId');
  }
}
