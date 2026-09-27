import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';
import 'package:mobile/modules/attendance/route_tracking/models/route_point.dart';
import 'package:mobile/modules/attendance/route_tracking/route_capture_rules.dart';
import 'package:mobile/modules/attendance/route_tracking/route_point_api.dart';
import 'package:mobile/modules/attendance/route_tracking/route_point_store.dart';
import 'package:mobile/modules/attendance/route_tracking/route_point_sync.dart';
import 'package:mobile/modules/attendance/route_tracking/route_tracking_config.dart';
import 'package:shared_preferences/shared_preferences.dart';

const _queueKey = 'route_points_pending_queue';
const _baseLat = 18.5204;
const _baseLng = 73.8567;

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late SharedPreferences prefs;
  late RoutePointStore store;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
    store = RoutePointStore(prefs);
  });

  group('1. synced UUID tombstones vs concurrent isolate writes', () {
    test('markSynced then stale queue write cannot resurrect pending UUIDs', () async {
      final point = _point(uuid: 'synced-1', lat: _baseLat, lng: _baseLng);
      await store.enqueue(point);
      expect(store.pendingPoints(), hasLength(1));

      await store.markSynced(['synced-1']);
      expect(store.pendingPoints(), isEmpty);

      // Simulate the other isolate writing a stale copy of the old queue.
      await prefs.setString(_queueKey, jsonEncode([point.toJson()]));
      await store.reload();

      expect(store.pendingPoints(), isEmpty);
      await store.enqueue(point);
      expect(store.pendingPoints(), isEmpty);
    });

    test('UI and FGS stores cannot re-queue a UUID after either marks it synced', () async {
      final ui = RoutePointStore(prefs);
      final fgs = RoutePointStore(prefs);
      final point = _point(uuid: 'cross-isolate', lat: _baseLat, lng: _baseLng);

      await fgs.enqueue(point);
      await Future.wait<void>([
        fgs.markSynced(['cross-isolate']),
        ui.enqueue(point),
      ]);

      await ui.reload();
      await fgs.reload();
      expect(ui.pendingPoints(), isEmpty);
      expect(fgs.pendingPoints(), isEmpty);

      await prefs.setString(_queueKey, jsonEncode([point.toJson()]));
      await ui.reload();
      expect(ui.pendingPoints(), isEmpty);
    });
  });

  group('2. compact a 20,000+ bloated queue', () {
    test('compacts jittered history and pending count drops below the soft cap', () async {
      final original = List<Map<String, dynamic>>.generate(20000, (index) {
        final jitterMeters = 5 + (index % 11); // 5–15 m
        final recordedAt = DateTime.utc(2026, 9, 26, 3, 30)
            .add(Duration(seconds: index))
            .toIso8601String();
        return _point(
          uuid: 'old-$index',
          lat: _baseLat + _metersToLat(jitterMeters.toDouble()),
          lng: _baseLng,
          recordedAt: recordedAt,
        ).toJson();
      });
      await prefs.setString(_queueKey, jsonEncode(original));
      await store.reload();
      expect(store.pendingPoints(), hasLength(20000));

      await store.compactBloatedQueue();
      final compacted = store.pendingPoints();
      expect(compacted.length, lessThanOrEqualTo(routePendingQueueSoftCap));
      expect(compacted.length, lessThan(50));
      expect(compacted.first.localUuid, 'old-0');
      expect(compacted.last.localUuid, 'old-19999');
    }, timeout: const Timeout(Duration(minutes: 2)));

    test('resurrected synced UUIDs stay out of pending after compact', () async {
      final original = List<Map<String, dynamic>>.generate(20000, (index) {
        return _point(
          uuid: 'dup-$index',
          lat: _baseLat + _metersToLat(8),
          lng: _baseLng,
        ).toJson();
      });
      await prefs.setString(_queueKey, jsonEncode(original));
      await store.reload();

      final synced = [for (var i = 0; i < 250; i++) 'dup-$i'];
      await store.markSynced(synced);
      await store.compactBloatedQueue();
      expect(store.pendingPoints().length, lessThan(50));

      await prefs.setString(_queueKey, jsonEncode(original));
      await store.reload();
      final pendingIds = store.pendingPoints().map((point) => point.localUuid).toSet();
      for (final uuid in synced) {
        expect(pendingIds.contains(uuid), isFalse, reason: '$uuid returned to pending');
      }
    }, timeout: const Timeout(Duration(minutes: 2)));
  });

  group('3. batch sync limits, drain, and backoff', () {
    test('uploads at most 100 points and 5 batches, then removes successes', () async {
      await _seedPending(store, count: 620);
      final api = _FakeRoutePointApi();
      final sync = RoutePointSync(store, api);

      await sync.syncPending(activeAttendanceId: 42);

      expect(api.batchSizes, everyElement(lessThanOrEqualTo(routeSyncBatchSize)));
      expect(api.batchSizes, hasLength(5));
      expect(api.batchSizes, everyElement(equals(100)));
      expect(store.pendingPoints(), hasLength(120));
    });

    test('failed batch keeps remaining points and does not tight-loop', () async {
      await _seedPending(store, count: 250);
      final api = _FakeRoutePointApi(failFromCall: 2);
      final sync = RoutePointSync(store, api);

      await sync.syncPending(activeAttendanceId: 42);

      expect(api.calls, 2);
      expect(store.pendingPoints(), hasLength(150));
    });

    test('in-flight sync is coalesced so concurrent ticks do not double-upload', () async {
      await _seedPending(store, count: 100);
      final api = _FakeRoutePointApi(delay: const Duration(milliseconds: 80));
      final sync = RoutePointSync(store, api);

      await Future.wait<void>([
        sync.syncPending(activeAttendanceId: 42),
        sync.syncPending(activeAttendanceId: 42),
        sync.syncPending(activeAttendanceId: 42),
      ]);

      expect(api.calls, 1);
      expect(store.pendingPoints(), isEmpty);
    });

    test('failure backoff is longer than the FGS tick so retries are not tight', () {
      expect(routeSyncBatchSize, 100);
      expect(routeForegroundTaskIntervalMs, 30000);
      expect(routeSyncFailureBackoffMin.inSeconds, 45);
      expect(routeSyncFailureBackoffMax.inMinutes, 5);
      expect(
        routeSyncFailureBackoffMin.inMilliseconds,
        greaterThan(routeForegroundTaskIntervalMs),
      );

      final delays = [
        for (var streak = 1; streak <= 6; streak++)
          (routeSyncFailureBackoffMin.inSeconds * (1 << (streak - 1).clamp(0, 4)))
              .clamp(
                routeSyncFailureBackoffMin.inSeconds,
                routeSyncFailureBackoffMax.inSeconds,
              ),
      ];
      expect(delays.first, 45);
      expect(delays.last, 300);
      expect(delays, everyElement(greaterThanOrEqualTo(45)));
    });
  });

  group('4. local UUID uniqueness', () {
    test('enqueue of the same UUID is a no-op', () async {
      final point = _point(uuid: 'once', lat: _baseLat, lng: _baseLng);
      await store.enqueue(point);
      await store.enqueue(point);
      await store.enqueue(
        _point(uuid: 'once', lat: _baseLat + 0.01, lng: _baseLng),
      );
      expect(store.pendingPoints(), hasLength(1));
      expect(store.allPoints(), hasLength(1));
    });
  });

  group('5–6. GPS capture rules', () {
    test('stationary 5–15m jitter is not captured', () async {
      final session = _activeSession(lastAt: DateTime.now().toUtc());
      for (final meters in [5.0, 8.0, 12.0, 15.0]) {
        final position = _position(
          lat: _baseLat + _metersToLat(meters),
          lng: _baseLng,
        );
        expect(
          RouteCaptureRules.shouldCapture(session: session, position: position),
          isFalse,
          reason: '${meters}m jitter should be skipped',
        );
      }
    });

    test('movement of 25m+ is captured and path distance stays accurate', () {
      const stepMeters = 30.0;
      var lastLat = _baseLat;
      var totalMeters = 0.0;

      for (var i = 1; i <= 4; i++) {
        final nextLat = lastLat + _metersToLat(stepMeters);
        final position = _position(lat: nextLat, lng: _baseLng);
        final session = RouteTrackingSession(
          attendanceId: 42,
          isActive: true,
          lastLatitude: lastLat,
          lastLongitude: _baseLng,
          lastRecordedAt: DateTime.now().toUtc().toIso8601String(),
        );
        final distance = Geolocator.distanceBetween(
          lastLat,
          _baseLng,
          nextLat,
          _baseLng,
        );
        expect(distance, greaterThanOrEqualTo(routeMovementThresholdMeters));
        expect(
          RouteCaptureRules.shouldCapture(session: session, position: position),
          isTrue,
        );
        totalMeters += distance;
        lastLat = nextLat;
      }

      expect(totalMeters, closeTo(120, 5));
    });

    test('inactive session never captures a route point', () async {
      await store.saveSession(
        const RouteTrackingSession(attendanceId: 42, isActive: false),
      );
      final captured = await RouteCaptureRules.captureFromPosition(
        store: store,
        position: _position(lat: _baseLat + _metersToLat(40), lng: _baseLng),
      );
      expect(captured, isNull);
      expect(store.pendingPoints(), isEmpty);
    });
  });

  group('7–10. punch in/out and single FGS contracts', () {
    test('source: only one FGS start, no GPS poll on repeat, punch-out stops tracking', () {
      final foreground = File(
        'lib/modules/attendance/route_tracking/route_tracking_foreground.dart',
      ).readAsStringSync();
      final service = File(
        'lib/modules/attendance/route_tracking/route_tracking_service.dart',
      ).readAsStringSync();

      expect(foreground.contains('restartService'), isFalse);
      expect(
        foreground.contains('if (await FlutterForegroundTask.isRunningService)'),
        isTrue,
      );
      expect(
        foreground.contains("sendDataToTask({'command': 'sync'})"),
        isTrue,
      );
      expect(foreground.contains('getCurrentPosition'), isFalse);
      expect(foreground.contains('_syncPendingQuietly()'), isTrue);

      final stopRequested = service.indexOf('Route tracking stop requested');
      final fgsStop = service.indexOf(
        'await RouteTrackingForeground.stop();',
        stopRequested,
      );
      final deferredSync = service.indexOf('Stop sync deferred');
      expect(stopRequested, greaterThan(0));
      expect(fgsStop, greaterThan(stopRequested));
      expect(deferredSync, greaterThan(fgsStop));
      expect(service.contains('copyWith(isActive: false)'), isTrue);

      expect(service.contains('if (!running)'), isTrue);
      expect(
        service.contains('Foreground service already running'),
        isTrue,
      );
    });

    test('capture is gated to an active punch-in session only', () async {
      await store.saveSession(
        const RouteTrackingSession(attendanceId: 0, isActive: true),
      );
      expect(
        await RouteCaptureRules.captureFromPosition(
          store: store,
          position: _position(lat: _baseLat, lng: _baseLng),
        ),
        isNull,
      );

      await store.saveSession(
        const RouteTrackingSession(attendanceId: 42, isActive: true),
      );
      final first = await RouteCaptureRules.captureFromPosition(
        store: store,
        position: _position(lat: _baseLat, lng: _baseLng),
      );
      expect(first, isNotNull);

      await store.saveSession(store.session!.copyWith(isActive: false));
      final afterPunchOut = await RouteCaptureRules.captureFromPosition(
        store: store,
        position: _position(
          lat: _baseLat + _metersToLat(40),
          lng: _baseLng,
        ),
      );
      expect(afterPunchOut, isNull);
    });
  });
}

RoutePoint _point({
  required String uuid,
  required double lat,
  required double lng,
  String recordedAt = '2026-09-26T10:00:00+05:30',
  int attendanceId = 42,
}) {
  return RoutePoint(
    localUuid: uuid,
    attendanceId: attendanceId,
    latitude: lat,
    longitude: lng,
    recordedAt: recordedAt,
    source: 'test',
    accuracy: 10,
  );
}

Future<void> _seedPending(RoutePointStore store, {required int count}) async {
  final prefs = await SharedPreferences.getInstance();
  final points = [
    for (var i = 0; i < count; i++)
      _point(
        uuid: 'p-$i',
        lat: _baseLat + _metersToLat(30.0 * (i + 1)),
        lng: _baseLng,
        recordedAt:
            '2026-09-26T10:${(i ~/ 60).toString().padLeft(2, '0')}:${(i % 60).toString().padLeft(2, '0')}+05:30',
      ).toJson(),
  ];
  await prefs.setString(_queueKey, jsonEncode(points));
  await store.reload();
}

RouteTrackingSession _activeSession({required DateTime lastAt}) {
  return RouteTrackingSession(
    attendanceId: 42,
    isActive: true,
    lastLatitude: _baseLat,
    lastLongitude: _baseLng,
    lastRecordedAt: lastAt.toIso8601String(),
  );
}

Position _position({required double lat, required double lng}) {
  return Position(
    latitude: lat,
    longitude: lng,
    timestamp: DateTime.now(),
    accuracy: 10,
    altitude: 0,
    altitudeAccuracy: 0,
    heading: 0,
    headingAccuracy: 0,
    speed: 0,
    speedAccuracy: 0,
  );
}

double _metersToLat(double meters) => meters / 111320.0;

class _FakeRoutePointApi extends RoutePointApi {
  _FakeRoutePointApi({
    this.failFromCall = 1 << 20,
    this.delay = Duration.zero,
  }) : super(Dio());

  final int failFromCall;
  final Duration delay;
  final List<int> batchSizes = [];
  int calls = 0;

  @override
  Future<void> uploadBatch({
    required int attendanceId,
    int? activeAttendanceId,
    required List<RoutePoint> points,
  }) async {
    if (delay > Duration.zero) {
      await Future<void>.delayed(delay);
    }
    calls += 1;
    batchSizes.add(points.length);
    if (calls >= failFromCall) {
      throw const RoutePointApiException('transient network error');
    }
  }
}
