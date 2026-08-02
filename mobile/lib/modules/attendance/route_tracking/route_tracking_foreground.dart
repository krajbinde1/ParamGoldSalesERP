import 'dart:async';
import 'dart:io';

import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'route_capture_rules.dart';
import 'route_point_api.dart';
import 'route_point_store.dart';
import 'route_point_sync.dart';
import 'route_tracking_config.dart';
import 'route_tracking_log.dart';

/// Top-level entry point for the Android foreground task isolate.
@pragma('vm:entry-point')
void routeTrackingStartCallback() {
  FlutterForegroundTask.setTaskHandler(RouteTrackingTaskHandler());
}

class RouteTrackingTaskHandler extends TaskHandler {
  StreamSubscription<Position>? _positionSubscription;
  RoutePointStore? _store;
  RoutePointSync? _sync;
  bool _handlingPosition = false;
  bool _ready = false;

  @override
  Future<void> onStart(DateTime timestamp, TaskStarter starter) async {
    routeTrackingLog('FGS onStart starter=${starter.name}');
    await _ensureReady();
    await _startPositionStream();
    await _captureOnce(source: '${routeTrackingSource}_fgs_start');
    await _syncPendingQuietly();
    _publishStatus();
  }

  @override
  void onRepeatEvent(DateTime timestamp) {
    unawaited(_onRepeat());
  }

  Future<void> _onRepeat() async {
    try {
      await _ensureReady();
      final session = _store?.session;
      if (session?.isActive != true) {
        routeTrackingLog('FGS repeat: session inactive — skipping capture');
        await _syncPendingQuietly();
        _publishStatus();
        return;
      }
      await _captureOnce(source: '${routeTrackingSource}_fgs_poll');
      await _syncPendingQuietly();
      _publishStatus();
    } catch (error, stackTrace) {
      routeTrackingLog('FGS repeat failed: $error\n$stackTrace');
    }
  }

  @override
  Future<void> onDestroy(DateTime timestamp, bool isTimeout) async {
    routeTrackingLog('FGS onDestroy isTimeout=$isTimeout');
    await _positionSubscription?.cancel();
    _positionSubscription = null;
  }

  @override
  void onReceiveData(Object data) {
    routeTrackingLog('FGS onReceiveData: $data');
    if (data is Map && data['command'] == 'sync') {
      unawaited(_syncPendingQuietly());
    }
  }

  Future<void> _ensureReady() async {
    if (_ready) return;
    final prefs = await SharedPreferences.getInstance();
    _store = RoutePointStore(prefs);
    final api = await RoutePointApi.create();
    _sync = RoutePointSync(_store!, api);
    _ready = true;
  }

  Future<void> _startPositionStream() async {
    if (_positionSubscription != null) return;
    final settings = RouteCaptureRules.streamLocationSettings(
      withGeolocatorNotification: false,
    );
    _positionSubscription =
        Geolocator.getPositionStream(locationSettings: settings).listen(
          (position) => unawaited(_onPosition(position)),
          onError: (Object error, StackTrace stackTrace) {
            routeTrackingLog('FGS position stream error: $error\n$stackTrace');
          },
          cancelOnError: false,
        );
    routeTrackingLog('FGS position stream started');
  }

  Future<void> _onPosition(Position position) async {
    if (_handlingPosition) return;
    _handlingPosition = true;
    try {
      await _ensureReady();
      final store = _store;
      if (store == null || store.session?.isActive != true) return;
      final point = await RouteCaptureRules.captureFromPosition(
        store: store,
        position: position,
        source: routeTrackingSource,
      );
      if (point != null) {
        await _syncPendingQuietly();
        _publishStatus();
      }
    } catch (error, stackTrace) {
      routeTrackingLog('FGS position handle failed: $error\n$stackTrace');
    } finally {
      _handlingPosition = false;
    }
  }

  Future<void> _captureOnce({required String source}) async {
    final store = _store;
    if (store == null || store.session?.isActive != true) return;
    await RouteCaptureRules.captureIfNeeded(store: store, source: source);
  }

  Future<void> _syncPendingQuietly() async {
    try {
      final store = _store;
      final sync = _sync;
      if (store == null || sync == null) return;
      final attendanceId = store.session?.attendanceId;
      await sync.syncPending(
        activeAttendanceId: attendanceId,
        allowClosedAttendance: true,
      );
    } catch (error) {
      routeTrackingLog('FGS sync failed (will retry): $error');
    }
  }

  void _publishStatus() {
    final store = _store;
    if (store == null) return;
    final session = store.session;
    final pending = store.pendingPoints().length;
    FlutterForegroundTask.sendDataToMain({
      'type': 'route_tracking_status',
      'is_active': session?.isActive == true,
      'attendance_id': session?.attendanceId,
      'last_recorded_at': session?.lastRecordedAt,
      'pending_sync_count': pending,
      'gps_status': session?.gpsStatus ?? 'unknown',
      'permission_status': session?.permissionStatus ?? 'unknown',
      'message': session?.isActive == true
          ? 'Route Tracking Active'
          : (session?.statusMessage ?? 'Route tracking stopped'),
    });
    unawaited(
      FlutterForegroundTask.updateService(
        notificationTitle: routeTrackingNotificationTitle,
        notificationText: pending > 0
            ? '$routeTrackingNotificationText · $pending pending sync'
            : routeTrackingNotificationText,
      ),
    );
  }
}

class RouteTrackingForeground {
  static bool _initialized = false;

  static Future<void> init() async {
    if (_initialized) return;
    FlutterForegroundTask.initCommunicationPort();
    FlutterForegroundTask.init(
      androidNotificationOptions: AndroidNotificationOptions(
        channelId: routeTrackingNotificationChannelId,
        channelName: routeTrackingNotificationChannelName,
        channelDescription:
            'Shows while ParamGold is tracking your field route.',
        onlyAlertOnce: true,
      ),
      iosNotificationOptions: const IOSNotificationOptions(
        showNotification: true,
        playSound: false,
      ),
      foregroundTaskOptions: ForegroundTaskOptions(
        eventAction: ForegroundTaskEventAction.repeat(
          routeForegroundTaskIntervalMs,
        ),
        // Never auto-start on boot/update — tracking starts only after punch-in.
        autoRunOnBoot: false,
        autoRunOnMyPackageReplaced: false,
        allowWakeLock: true,
        allowWifiLock: true,
      ),
    );
    _initialized = true;
    routeTrackingLog('Foreground task initialized');
  }

  static Future<bool> start() async {
    await init();
    if (await FlutterForegroundTask.isRunningService) {
      final result = await FlutterForegroundTask.restartService();
      routeTrackingLog('FGS restart result=$result');
      return result is ServiceRequestSuccess;
    }

    final result = await FlutterForegroundTask.startService(
      serviceId: routeForegroundServiceId,
      notificationTitle: routeTrackingNotificationTitle,
      notificationText: routeTrackingNotificationText,
      callback: routeTrackingStartCallback,
      serviceTypes: Platform.isAndroid
          ? [ForegroundServiceTypes.location]
          : null,
    );
    routeTrackingLog('FGS start result=$result');
    return result is ServiceRequestSuccess;
  }

  static Future<void> stop() async {
    if (await FlutterForegroundTask.isRunningService) {
      final result = await FlutterForegroundTask.stopService();
      routeTrackingLog('FGS stop result=$result');
    }
  }

  static Future<bool> get isRunning => FlutterForegroundTask.isRunningService;

  static Future<void> requestSync() async {
    if (await FlutterForegroundTask.isRunningService) {
      FlutterForegroundTask.sendDataToTask({'command': 'sync'});
    }
  }
}
