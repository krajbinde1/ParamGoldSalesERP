import 'dart:async';
import 'dart:convert';

import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'models/route_point.dart';
import 'route_tracking_config.dart';
import 'route_tracking_log.dart';

class RoutePointStore {
  RoutePointStore(this._prefs);

  static const _queueKey = 'route_points_pending_queue';
  static const _sessionKey = 'route_tracking_session';
  static const _syncedKey = 'route_points_synced_uuids';

  final SharedPreferences _prefs;
  Future<void> _writeChain = Future.value();

  RouteTrackingSession? get session {
    final raw = _prefs.getString(_sessionKey);
    if (raw == null) return null;
    return RouteTrackingSession.fromJson(
      Map<String, dynamic>.from(jsonDecode(raw) as Map),
    );
  }

  Future<void> reload() async {
    await _prefs.reload();
  }

  Future<void> saveSession(RouteTrackingSession? value) async {
    await _locked(() async {
      await _prefs.reload();
      if (value == null) {
        await _prefs.remove(_sessionKey);
        return;
      }
      await _prefs.setString(_sessionKey, jsonEncode(value.toJson()));
    });
  }

  List<RoutePoint> pendingPoints() {
    final synced = _syncedUuids();
    return allPoints()
        .where(
          (point) =>
              point.syncStatus == 'pending' &&
              point.localUuid.isNotEmpty &&
              !synced.contains(point.localUuid),
        )
        .toList();
  }

  List<RoutePoint> allPoints() {
    final raw = _prefs.getString(_queueKey);
    if (raw == null) return const [];
    try {
      return (jsonDecode(raw) as List)
          .map(
            (item) =>
                RoutePoint.fromJson(Map<String, dynamic>.from(item as Map)),
          )
          .toList();
    } catch (error) {
      routeTrackingLog('Pending queue parse failed: $error');
      return const [];
    }
  }

  Future<void> enqueue(RoutePoint point) async {
    await _locked(() async {
      await _prefs.reload();
      if (point.localUuid.isEmpty) return;
      if (_syncedUuids().contains(point.localUuid)) {
        routeTrackingLog(
          'Skip enqueue: uuid already synced ${point.localUuid}',
        );
        return;
      }

      final points = _mergeByUuid(allPoints());
      if (points.any((existing) => existing.localUuid == point.localUuid)) {
        return;
      }

      final last = points.isEmpty ? null : points.last;
      if (last != null &&
          last.attendanceId == point.attendanceId &&
          _isNearDuplicate(last, point)) {
        routeTrackingLog(
          'Skip enqueue: near-duplicate of last queued point',
        );
        return;
      }

      points.add(point);
      await _persistQueue(_pruneSynced(points));
    });
  }

  Future<void> markSynced(Iterable<String> localUuids) async {
    final incoming = localUuids.where((id) => id.isNotEmpty).toSet();
    if (incoming.isEmpty) return;
    await _locked(() async {
      await _prefs.reload();
      final synced = _syncedUuids()..addAll(incoming);
      await _persistSynced(synced);
      final remaining = _pruneSynced(allPoints(), synced: synced);
      await _persistQueue(remaining);
      routeTrackingLog(
        'Marked ${incoming.length} point(s) synced; '
        'pending=${remaining.length}',
      );
    });
  }

  Future<void> clearPointsForAttendance(int attendanceId) async {
    await _locked(() async {
      await _prefs.reload();
      final points = allPoints()
          .where((point) => point.attendanceId != attendanceId)
          .toList();
      await _persistQueue(points);
    });
  }

  Future<void> retainOnlyAttendance(int attendanceId) async {
    await _locked(() async {
      await _prefs.reload();
      final points = allPoints()
          .where((point) => point.attendanceId == attendanceId)
          .toList();
      await _persistQueue(_pruneSynced(points));
    });
  }

  Future<void> compactBloatedQueue() async {
    await _locked(() async {
      await _prefs.reload();
      final pending = _pruneSynced(allPoints());
      if (pending.length <= routePendingQueueSoftCap) {
        if (pending.length != allPoints().length) {
          await _persistQueue(pending);
        }
        return;
      }
      final compacted = _compactByMovement(pending);
      routeTrackingLog(
        'Compacted bloated queue ${pending.length} → ${compacted.length}',
      );
      await _persistQueue(compacted);
    });
  }

  Future<void> clearSession() async {
    await _locked(() async {
      await _prefs.reload();
      await _prefs.remove(_sessionKey);
    });
  }

  Future<void> _locked(Future<void> Function() action) {
    final previous = _writeChain;
    final done = Completer<void>();
    _writeChain = done.future;
    return previous
        .catchError((_) {})
        .then((_) => action())
        .whenComplete(() {
          if (!done.isCompleted) done.complete();
        });
  }

  Set<String> _syncedUuids() {
    final raw = _prefs.getString(_syncedKey);
    if (raw == null || raw.isEmpty) return <String>{};
    try {
      return (jsonDecode(raw) as List).map((item) => '$item').toSet();
    } catch (_) {
      return <String>{};
    }
  }

  Future<void> _persistSynced(Set<String> synced) async {
    var ids = synced.toList();
    if (ids.length > 4000) {
      ids = ids.sublist(ids.length - 4000);
    }
    await _prefs.setString(_syncedKey, jsonEncode(ids));
  }

  List<RoutePoint> _pruneSynced(
    List<RoutePoint> points, {
    Set<String>? synced,
  }) {
    final known = synced ?? _syncedUuids();
    final byUuid = _mergeByUuid(points);
    return byUuid
        .where(
          (point) =>
              point.localUuid.isNotEmpty && !known.contains(point.localUuid),
        )
        .toList();
  }

  List<RoutePoint> _mergeByUuid(List<RoutePoint> points) {
    final byUuid = <String, RoutePoint>{};
    for (final point in points) {
      if (point.localUuid.isEmpty) continue;
      byUuid.putIfAbsent(point.localUuid, () => point);
    }
    final merged = byUuid.values.toList()
      ..sort((a, b) => a.recordedAt.compareTo(b.recordedAt));
    return merged;
  }

  List<RoutePoint> _compactByMovement(List<RoutePoint> points) {
    if (points.length <= 2) return points;
    final kept = <RoutePoint>[points.first];
    for (var i = 1; i < points.length - 1; i++) {
      final previous = kept.last;
      final current = points[i];
      final distance = Geolocator.distanceBetween(
        previous.latitude,
        previous.longitude,
        current.latitude,
        current.longitude,
      );
      if (distance >= routeStationaryRadiusMeters) {
        kept.add(current);
      }
    }
    final last = points.last;
    if (kept.last.localUuid != last.localUuid) {
      kept.add(last);
    }
    return kept;
  }

  bool _isNearDuplicate(RoutePoint a, RoutePoint b) {
    final distance = Geolocator.distanceBetween(
      a.latitude,
      a.longitude,
      b.latitude,
      b.longitude,
    );
    if (distance > routeStationaryRadiusMeters) return false;
    final aAt = DateTime.tryParse(a.recordedAt);
    final bAt = DateTime.tryParse(b.recordedAt);
    if (aAt == null || bAt == null) return distance <= 5;
    return bAt.difference(aAt).abs() <= routeDuplicateCoordWindow;
  }

  Future<void> _persistQueue(List<RoutePoint> points) async {
    await _prefs.setString(
      _queueKey,
      jsonEncode(points.map((point) => point.toJson()).toList()),
    );
  }
}
