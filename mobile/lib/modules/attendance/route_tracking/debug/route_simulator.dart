import 'dart:async';
import 'dart:math';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';

import '../models/route_point.dart';
import '../route_capture_rules.dart';
import '../route_point_api.dart';
import '../route_point_store.dart';
import '../route_point_sync.dart';
import '../route_tracking_log.dart';
import 'route_tracking_debug_config.dart';

enum RouteSimulationStatus { idle, running, completed, stopped, failed }

class RouteSimulationProgress {
  const RouteSimulationProgress({
    required this.status,
    this.currentPoint = 0,
    this.totalPoints = routeSimulationPointCount,
    this.message,
  });

  final RouteSimulationStatus status;
  final int currentPoint;
  final int totalPoints;
  final String? message;

  bool get isRunning => status == RouteSimulationStatus.running;

  RouteSimulationProgress copyWith({
    RouteSimulationStatus? status,
    int? currentPoint,
    int? totalPoints,
    String? message,
  }) {
    return RouteSimulationProgress(
      status: status ?? this.status,
      currentPoint: currentPoint ?? this.currentPoint,
      totalPoints: totalPoints ?? this.totalPoints,
      message: message ?? this.message,
    );
  }
}

/// Debug-only route point generator. Uses [RoutePointStore], [RoutePointSync]
/// and [RoutePointApi] — the same persistence/sync pipeline as live tracking.
class RouteSimulator {
  RouteSimulator._();

  static final RouteSimulator instance = RouteSimulator._();

  final _random = Random();
  Timer? _timer;
  int _generated = 0;
  double _latitude = 0;
  double _longitude = 0;
  RoutePointStore? _store;
  RoutePointSync? _sync;
  int? _attendanceId;
  void Function(RouteSimulationProgress progress)? _onProgress;

  RouteSimulationProgress _progress = const RouteSimulationProgress(
    status: RouteSimulationStatus.idle,
  );

  RouteSimulationProgress get progress => _progress;

  Future<void> _ensurePipeline() async {
    final prefs = await SharedPreferences.getInstance();
    _store ??= RoutePointStore(prefs);
    _sync ??= RoutePointSync(_store!, await RoutePointApi.create());
  }

  Future<void> start({
    required int attendanceId,
    void Function(RouteSimulationProgress progress)? onProgress,
  }) async {
    if (!routeSimulationEnabled) {
      throw StateError('Route simulation is disabled in this build.');
    }
    if (_progress.isRunning) {
      throw StateError('Route simulation is already running.');
    }

    await _ensurePipeline();
    final store = _store!;
    final session = store.session;
    if (session?.isActive != true || session!.attendanceId != attendanceId) {
      throw StateError(
        'Active route tracking session required before simulation.',
      );
    }

    _attendanceId = attendanceId;
    _onProgress = onProgress;
    _generated = 0;

    final start = await _resolveStartCoordinate(store, session);
    _latitude = start.latitude;
    _longitude = start.longitude;

    _emit(
      RouteSimulationProgress(
        status: RouteSimulationStatus.running,
        currentPoint: 0,
        message: 'Starting route simulation…',
      ),
    );

    routeTrackingLog(
      'Debug route simulation started: attendanceId=$attendanceId '
      'start=($_latitude, $_longitude) target=$routeSimulationPointCount points',
    );

    await _generatePoint();
    _timer = Timer.periodic(routeSimulationInterval, (_) async {
      if (_generated >= routeSimulationPointCount) {
        await _complete();
        return;
      }
      await _generatePoint();
    });
  }

  Future<void> stop() async {
    if (!_progress.isRunning) return;
    _timer?.cancel();
    _timer = null;
    await _syncPending();
    _emit(
      RouteSimulationProgress(
        status: RouteSimulationStatus.stopped,
        currentPoint: _generated,
        message: 'Route simulation stopped ($_generated points saved).',
      ),
    );
    routeTrackingLog('Debug route simulation stopped at $_generated points');
    _resetRunState();
  }

  Future<({double latitude, double longitude})> _resolveStartCoordinate(
    RoutePointStore store,
    RouteTrackingSession session,
  ) async {
    if (session.lastLatitude != null && session.lastLongitude != null) {
      return (
        latitude: session.lastLatitude!,
        longitude: session.lastLongitude!,
      );
    }

    try {
      final position = await RouteCaptureRules.fetchCurrentPosition();
      return (latitude: position.latitude, longitude: position.longitude);
    } catch (_) {
      // Bengaluru fallback for offline/dev simulation.
      return (latitude: 12.9716, longitude: 77.5946);
    }
  }

  Future<void> _generatePoint() async {
    if (_generated >= routeSimulationPointCount) {
      await _complete();
      return;
    }

    final store = _store!;
    final session = store.session;
    if (session == null || !session.isActive) {
      await _fail('Route tracking session is no longer active.');
      return;
    }

    if (_generated > 0) {
      final distance =
          routeSimulationMinMeters +
          _random.nextDouble() *
              (routeSimulationMaxMeters - routeSimulationMinMeters);
      final bearing = _random.nextDouble() * 2 * pi;
      final moved = _offsetMeters(
        latitude: _latitude,
        longitude: _longitude,
        distanceMeters: distance,
        bearingRadians: bearing,
      );
      _latitude = moved.latitude;
      _longitude = moved.longitude;
    }

    final now = DateTime.now().toUtc();
    final point = RoutePoint(
      localUuid: const Uuid().v4(),
      attendanceId: _attendanceId!,
      latitude: _latitude,
      longitude: _longitude,
      accuracy: 8 + _random.nextDouble() * 12,
      speed: 1.2 + _random.nextDouble() * 2.5,
      recordedAt: RoutePoint.formatRecordedAt(now),
      source: routeSimulationSource,
    );

    await store.enqueue(point);
    await store.saveSession(
      session.copyWith(
        lastLatitude: point.latitude,
        lastLongitude: point.longitude,
        lastRecordedAt: point.recordedAt,
      ),
    );

    _generated++;
    routeTrackingLog(
      'Debug simulated point $_generated/$routeSimulationPointCount: '
      'uuid=${point.localUuid} (${point.latitude}, ${point.longitude})',
    );

    _emit(
      RouteSimulationProgress(
        status: RouteSimulationStatus.running,
        currentPoint: _generated,
        message: 'Generating point $_generated of $routeSimulationPointCount…',
      ),
    );

    await _syncPending();

    if (_generated >= routeSimulationPointCount) {
      await _complete();
    }
  }

  Future<void> _complete() async {
    _timer?.cancel();
    _timer = null;
    await _syncPending();
    _emit(
      RouteSimulationProgress(
        status: RouteSimulationStatus.completed,
        currentPoint: _generated,
        message: 'Route Simulation Completed Successfully',
      ),
    );
    routeTrackingLog(
      'Debug route simulation completed: $_generated points synced',
    );
    _resetRunState();
  }

  Future<void> _fail(String message) async {
    _timer?.cancel();
    _timer = null;
    _emit(
      RouteSimulationProgress(
        status: RouteSimulationStatus.failed,
        currentPoint: _generated,
        message: message,
      ),
    );
    routeTrackingLog('Debug route simulation failed: $message');
    _resetRunState();
  }

  Future<void> _syncPending() async {
    if (_attendanceId == null) return;
    try {
      await _sync?.syncPending(activeAttendanceId: _attendanceId);
    } catch (error, stackTrace) {
      routeTrackingLog('Debug simulation sync failed: $error\n$stackTrace');
    }
  }

  void _emit(RouteSimulationProgress value) {
    _progress = value;
    _onProgress?.call(value);
  }

  void _resetRunState() {
    _attendanceId = null;
    _onProgress = null;
  }

  ({double latitude, double longitude}) _offsetMeters({
    required double latitude,
    required double longitude,
    required double distanceMeters,
    required double bearingRadians,
  }) {
    final latRad = latitude * pi / 180;
    final earthRadius = 6378137.0;
    final angular = distanceMeters / earthRadius;
    final newLatRad = asin(
      sin(latRad) * cos(angular) +
          cos(latRad) * sin(angular) * cos(bearingRadians),
    );
    final newLngRad =
        longitude * pi / 180 +
        atan2(
          sin(bearingRadians) * sin(angular) * cos(latRad),
          cos(angular) - sin(latRad) * sin(newLatRad),
        );

    return (latitude: newLatRad * 180 / pi, longitude: newLngRad * 180 / pi);
  }
}
