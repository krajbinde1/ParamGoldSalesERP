import 'dart:io';

import 'package:geolocator/geolocator.dart';
import 'package:uuid/uuid.dart';

import 'models/route_point.dart';
import 'route_point_store.dart';
import 'route_tracking_config.dart';
import 'route_tracking_log.dart';

class RouteCaptureRules {
  static const duplicateDistanceMeters = 5.0;

  static Duration get captureInterval => routeCaptureInterval;
  static double get movementThresholdMeters => routeMovementThresholdMeters;
  static double get maxAccuracyMeters => routeMaxAccuracyMeters;

  static bool isValidCoordinate(double latitude, double longitude) {
    if (latitude.abs() < 0.000001 && longitude.abs() < 0.000001) {
      return false;
    }
    if (latitude < -90 || latitude > 90) return false;
    if (longitude < -180 || longitude > 180) return false;
    return true;
  }

  static bool hasAcceptableAccuracy(double? accuracy) {
    if (accuracy == null) return true;
    return accuracy > 0 && accuracy <= maxAccuracyMeters;
  }

  static DateTime? parseRecordedAt(String value) {
    final parsed = DateTime.tryParse(value);
    if (parsed != null) return parsed.toUtc();

    final normalized = value.contains('T')
        ? value
        : value.replaceFirst(' ', 'T');
    final fallback = DateTime.tryParse(normalized);
    return fallback?.toUtc();
  }

  static Duration? elapsedSince(String? lastRecordedAt) {
    if (lastRecordedAt == null || lastRecordedAt.isEmpty) return null;
    final parsed = parseRecordedAt(lastRecordedAt);
    if (parsed == null) {
      routeTrackingLog('Could not parse lastRecordedAt: $lastRecordedAt');
      return null;
    }
    return DateTime.now().toUtc().difference(parsed);
  }

  static bool shouldCapture({
    required RouteTrackingSession session,
    required Position position,
  }) {
    if (!isValidCoordinate(position.latitude, position.longitude)) {
      routeTrackingLog(
        'Skip capture: invalid coordinates '
        '(${position.latitude}, ${position.longitude})',
      );
      return false;
    }
    if (!hasAcceptableAccuracy(position.accuracy)) {
      routeTrackingLog(
        'Skip capture: poor accuracy ${position.accuracy}m '
        '(max $maxAccuracyMeters m)',
      );
      return false;
    }

    final lastLat = session.lastLatitude;
    final lastLng = session.lastLongitude;
    final lastRecordedAt = session.lastRecordedAt;

    if (lastLat == null || lastLng == null || lastRecordedAt == null) {
      routeTrackingLog('Capture allowed: first point for session');
      return true;
    }

    final elapsed = elapsedSince(lastRecordedAt);
    final distance = Geolocator.distanceBetween(
      lastLat,
      lastLng,
      position.latitude,
      position.longitude,
    );

    routeTrackingLog(
      'Evaluate capture: distance=${distance.toStringAsFixed(1)}m '
      'elapsed=${elapsed?.inSeconds ?? 'unparsed'}s '
      'pos=(${position.latitude}, ${position.longitude}) '
      'last=($lastLat, $lastLng)',
    );

    if (elapsed != null && elapsed >= captureInterval) {
      routeTrackingLog(
        'Capture allowed: ${captureInterval.inSeconds}s interval reached',
      );
      return true;
    }

    if (distance >= movementThresholdMeters) {
      routeTrackingLog(
        'Capture allowed: moved ${distance.toStringAsFixed(1)}m '
        '(>= $movementThresholdMeters m)',
      );
      return true;
    }

    if (distance <= duplicateDistanceMeters) {
      routeTrackingLog(
        'Skip capture: unchanged coordinates within ${duplicateDistanceMeters}m',
      );
      return false;
    }

    routeTrackingLog(
      'Skip capture: moved ${distance.toStringAsFixed(1)}m and interval not met',
    );
    return false;
  }

  static RoutePoint buildPoint({
    required int attendanceId,
    required Position position,
    required String source,
  }) {
    return RoutePoint(
      localUuid: const Uuid().v4(),
      attendanceId: attendanceId,
      latitude: position.latitude,
      longitude: position.longitude,
      accuracy: position.accuracy,
      speed: position.speed >= 0 ? position.speed : null,
      recordedAt: RoutePoint.formatRecordedAt(
        position.timestamp.toUtc().isBefore(
              DateTime.fromMillisecondsSinceEpoch(0),
            )
            ? DateTime.now().toUtc()
            : position.timestamp.toUtc(),
      ),
      source: source,
    );
  }

  static LocationSettings locationSettings() {
    if (Platform.isAndroid) {
      return AndroidSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 0,
        intervalDuration: const Duration(seconds: 5),
        forceLocationManager: false,
      );
    }
    return const LocationSettings(
      accuracy: LocationAccuracy.high,
      timeLimit: Duration(seconds: 25),
    );
  }

  static LocationSettings streamLocationSettings() {
    if (Platform.isAndroid) {
      return AndroidSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 10,
        intervalDuration: const Duration(seconds: 10),
        forceLocationManager: false,
        foregroundNotificationConfig: const ForegroundNotificationConfig(
          notificationTitle: routeTrackingNotificationTitle,
          notificationText: routeTrackingNotificationText,
          notificationChannelName: routeTrackingNotificationChannelName,
          setOngoing: true,
        ),
      );
    }
    return AppleSettings(
      accuracy: LocationAccuracy.high,
      distanceFilter: 10,
      activityType: ActivityType.otherNavigation,
      pauseLocationUpdatesAutomatically: false,
    );
  }

  static Future<Position> fetchCurrentPosition() async {
    routeTrackingLog('Fetching current GPS position…');
    final position = await Geolocator.getCurrentPosition(
      locationSettings: locationSettings(),
    );
    routeTrackingLog(
      'GPS received: (${position.latitude}, ${position.longitude}) '
      'accuracy=${position.accuracy}m speed=${position.speed}',
    );
    return position;
  }

  static Future<RoutePoint?> captureFromPosition({
    required RoutePointStore store,
    required Position position,
    String source = routeTrackingSource,
  }) async {
    final session = store.session;
    if (session == null || !session.isActive || session.attendanceId <= 0) {
      routeTrackingLog(
        'Skip capture: inactive session '
        '(active=${session?.isActive}, id=${session?.attendanceId})',
      );
      return null;
    }

    if (!shouldCapture(session: session, position: position)) {
      return null;
    }

    final point = buildPoint(
      attendanceId: session.attendanceId,
      position: position,
      source: source,
    );
    await store.enqueue(point);
    await store.saveSession(
      session.copyWith(
        lastLatitude: point.latitude,
        lastLongitude: point.longitude,
        lastRecordedAt: point.recordedAt,
      ),
    );
    routeTrackingLog(
      'Point captured [$source]: uuid=${point.localUuid} '
      'attendance=${point.attendanceId} '
      '(${point.latitude}, ${point.longitude}) '
      'accuracy=${point.accuracy} at ${point.recordedAt}',
    );
    return point;
  }

  static Future<RoutePoint?> captureIfNeeded({
    required RoutePointStore store,
    String source = routeTrackingSource,
  }) async {
    try {
      final position = await fetchCurrentPosition();
      return captureFromPosition(
        store: store,
        position: position,
        source: source,
      );
    } catch (error, stackTrace) {
      routeTrackingLog('Capture failed [$source]: $error\n$stackTrace');
      return null;
    }
  }
}
