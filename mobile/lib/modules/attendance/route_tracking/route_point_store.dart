import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import 'models/route_point.dart';

class RoutePointStore {
  RoutePointStore(this._prefs);

  static const _queueKey = 'route_points_pending_queue';
  static const _sessionKey = 'route_tracking_session';

  final SharedPreferences _prefs;

  RouteTrackingSession? get session {
    final raw = _prefs.getString(_sessionKey);
    if (raw == null) return null;
    return RouteTrackingSession.fromJson(
      Map<String, dynamic>.from(jsonDecode(raw) as Map),
    );
  }

  Future<void> saveSession(RouteTrackingSession? value) async {
    if (value == null) {
      await _prefs.remove(_sessionKey);
      return;
    }
    await _prefs.setString(_sessionKey, jsonEncode(value.toJson()));
  }

  List<RoutePoint> pendingPoints() {
    final raw = _prefs.getString(_queueKey);
    if (raw == null) return const [];
    return (jsonDecode(raw) as List)
        .map(
          (item) => RoutePoint.fromJson(Map<String, dynamic>.from(item as Map)),
        )
        .where((point) => point.syncStatus == 'pending')
        .toList();
  }

  List<RoutePoint> allPoints() {
    final raw = _prefs.getString(_queueKey);
    if (raw == null) return const [];
    return (jsonDecode(raw) as List)
        .map(
          (item) => RoutePoint.fromJson(Map<String, dynamic>.from(item as Map)),
        )
        .toList();
  }

  Future<void> enqueue(RoutePoint point) async {
    final points = allPoints();
    if (points.any((existing) => existing.localUuid == point.localUuid)) {
      return;
    }
    points.add(point);
    await _persistQueue(points);
  }

  Future<void> markSynced(Iterable<String> localUuids) async {
    final synced = localUuids.toSet();
    if (synced.isEmpty) return;
    final points = allPoints()
        .where((point) => !synced.contains(point.localUuid))
        .toList();
    await _persistQueue(points);
  }

  Future<void> clearPointsForAttendance(int attendanceId) async {
    final points = allPoints()
        .where((point) => point.attendanceId != attendanceId)
        .toList();
    await _persistQueue(points);
  }

  Future<void> retainOnlyAttendance(int attendanceId) async {
    final points = allPoints()
        .where((point) => point.attendanceId == attendanceId)
        .toList();
    await _persistQueue(points);
  }

  Future<void> clearSession() async {
    await _prefs.remove(_sessionKey);
  }

  Future<void> _persistQueue(List<RoutePoint> points) async {
    await _prefs.setString(
      _queueKey,
      jsonEncode(points.map((point) => point.toJson()).toList()),
    );
  }
}
