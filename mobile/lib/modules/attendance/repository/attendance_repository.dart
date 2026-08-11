import 'dart:convert';
import 'dart:io';
import 'package:shared_preferences/shared_preferences.dart';
import '../api/attendance_api_service.dart';
import '../models/attendance.dart';
import '../models/attendance_format.dart';
import '../models/punch_capture.dart';

class AttendanceRepository {
  AttendanceRepository(this.api, this.prefs);
  final AttendanceApiService api;
  final SharedPreferences prefs;
  static const _queueKey = 'attendance_sync_queue',
      _todayKey = 'attendance_local_today',
      _syncErrorKey = 'attendance_sync_error';
  Future<void>? _syncInFlight;
  Future<Attendance?> today() async {
    await syncPending();
    try {
      final value = await api.today();
      if (value != null) {
        await _persistToday(value, queue: _readQueue());
        return value;
      }
      await clearLocalTodayCache();
      return null;
    } catch (_) {
      return _localToday();
    }
  }

  int? get localTodayAttendanceId => _localToday()?.id;

  // TEST ONLY - REMOVE BEFORE PRODUCTION
  Future<String> resetTodayTest() async {
    final message = await api.resetToday();
    await clearLocalTodayCache();
    return message;
  }

  Future<Attendance?> fetchTodayFresh() async {
    try {
      final value = await api.today();
      if (value != null) {
        await _persistToday(value, queue: _readQueue());
      } else {
        await prefs.remove(_todayKey);
      }
      return value;
    } catch (_) {
      return _localToday();
    }
  }

  Future<void> clearLocalTodayCache() async {
    await prefs.remove(_todayKey);
    await prefs.remove(_syncErrorKey);
    await prefs.setString(_queueKey, '[]');
  }

  Future<List<Attendance>> history(DateTime month) async {
    await syncPending();
    return api.history(month);
  }

  Future<AttendanceMonthlySummary> monthlySummary(DateTime month) async {
    await syncPending();
    return api.monthlySummary(month);
  }

  Future<Attendance> punch(String action, PunchCapture c) async {
    try {
      final value = await api.punch(action, c);
      await _persistToday(value, queue: _readQueue());
      return value;
    } on AttendanceApiException catch (e) {
      if (e.message != 'No internet connection.') rethrow;
      return _saveOffline(action, c);
    } on SocketException {
      return _saveOffline(action, c);
    }
  }

  Future<Attendance> _saveOffline(String action, PunchCapture c) async {
    final current = _localToday();
    if (action == 'punch-in' && current?.punchIn != null) {
      throw const AttendanceApiException('You have already punched in today.');
    }
    if (action == 'punch-out' && current?.punchIn == null) {
      throw const AttendanceApiException(
        'Punch in is required before punch out.',
      );
    }
    final value = Attendance(
      date: AttendanceFormat.istNow(),
      punchIn: action == 'punch-in' ? c.capturedAt : current!.punchIn,
      punchOut: action == 'punch-out' ? c.capturedAt : null,
      inLatitude: action == 'punch-in' ? c.latitude : current?.inLatitude,
      inLongitude: action == 'punch-in' ? c.longitude : current?.inLongitude,
      outLatitude: action == 'punch-out' ? c.latitude : null,
      outLongitude: action == 'punch-out' ? c.longitude : null,
      inAddress: action == 'punch-in' ? c.address : current?.inAddress,
      outAddress: action == 'punch-out' ? c.address : null,
      inPhoto: action == 'punch-in' ? c.photoPath : current?.inPhoto,
      outPhoto: action == 'punch-out' ? c.photoPath : null,
      status: action == 'punch-in'
          ? 'Punched In'
          : _statusFromDuration(current!.punchIn!, c.capturedAt),
      isPendingSync: true,
      workingHours: action == 'punch-out'
          ? _duration(current!.punchIn!, c.capturedAt)
          : null,
    );
    final queue = _readQueue();
    queue.add({'action': action, 'capture': c.toJson()});
    await prefs.setString(_queueKey, jsonEncode(queue));
    await _persistToday(value, queue: queue);
    return value;
  }

  Future<void> syncPending() async {
    if (_syncInFlight != null) return _syncInFlight!;
    _syncInFlight = _syncPendingInternal();
    try {
      await _syncInFlight;
    } finally {
      _syncInFlight = null;
    }
  }

  Future<void> _syncPendingInternal() async {
    var queue = _readQueue();
    if (queue.isEmpty) {
      await _reconcileOrphanedPending();
      return;
    }

    // Reconcile first because a request can reach Laravel while its response
    // is lost. Do not upload actions the server has already completed.
    try {
      final server = await api.today();
      queue = queue.where((item) {
        final action = item['action'];
        if (action == 'punch-in' && server?.punchIn != null) return false;
        if (action == 'punch-out' && server?.punchOut != null) return false;
        return true;
      }).toList();
      await prefs.setString(_queueKey, jsonEncode(queue));
      if (server != null) {
        await _persistToday(server, queue: queue);
      }
      if (queue.isEmpty) {
        await prefs.remove(_syncErrorKey);
        return;
      }
    } on AttendanceApiException {
      // Continue with the queue; the upload below retains normal retry rules.
    }

    var done = 0;
    String? lastError;
    for (final item in queue) {
      try {
        final value = await api.punch(
          item['action'] as String,
          PunchCapture.fromJson(
            Map<String, dynamic>.from(item['capture'] as Map),
          ),
        );
        done++;
        final remaining = queue.skip(done).toList();
        await _persistToday(value, queue: remaining);
      } on AttendanceApiException catch (error) {
        final duplicate =
            (item['action'] == 'punch-in' &&
                error.message == 'Already punched in.') ||
            (item['action'] == 'punch-out' &&
                error.message == 'Already punched out.');
        if (duplicate) {
          done++;
          continue;
        }
        lastError = error.message;
        break;
      } catch (error) {
        lastError = error.toString();
        break;
      }
    }
    if (done > 0) {
      queue = queue.skip(done).toList();
      await prefs.setString(_queueKey, jsonEncode(queue));
      if (queue.isEmpty) {
        try {
          final serverValue = await api.today();
          if (serverValue != null) {
            await _persistToday(serverValue, queue: queue);
          } else {
            await _alignPendingFlag(queue);
          }
          await prefs.remove(_syncErrorKey);
        } catch (error) {
          await _alignPendingFlag(queue);
          await prefs.setString(_syncErrorKey, error.toString());
        }
      } else {
        await _alignPendingFlag(queue);
        if (lastError != null) {
          await prefs.setString(_syncErrorKey, lastError);
        }
      }
    } else if (lastError != null) {
      await prefs.setString(_syncErrorKey, lastError);
    }
  }

  List<Map<String, dynamic>> _readQueue() =>
      (jsonDecode(prefs.getString(_queueKey) ?? '[]') as List)
          .map((e) => Map<String, dynamic>.from(e as Map))
          .toList();

  Future<void> _persistToday(
    Attendance value, {
    required List<Map<String, dynamic>> queue,
  }) async {
    final pending = queue.isNotEmpty;
    final toSave = Attendance(
      id: value.id,
      employeeId: value.employeeId,
      date: value.date,
      punchIn: value.punchIn,
      punchOut: value.punchOut,
      inLatitude: value.inLatitude,
      inLongitude: value.inLongitude,
      outLatitude: value.outLatitude,
      outLongitude: value.outLongitude,
      inAddress: value.inAddress,
      outAddress: value.outAddress,
      inPhoto: value.inPhoto,
      outPhoto: value.outPhoto,
      workingHours: value.workingHours,
      status: value.status,
      isPendingSync: pending,
    );
    await prefs.setString(_todayKey, jsonEncode(toSave.toJson()));
  }

  Future<void> _alignPendingFlag(List<Map<String, dynamic>> queue) async {
    final local = _localToday();
    if (local == null) return;
    await _persistToday(local, queue: queue);
  }

  Future<void> _reconcileOrphanedPending() async {
    final local = _localToday();
    if (local?.isPendingSync != true) return;
    try {
      final server = await api.today();
      if (server != null) {
        await _persistToday(server, queue: const []);
        await prefs.remove(_syncErrorKey);
      } else {
        await _persistToday(local!, queue: const []);
      }
    } on AttendanceApiException catch (error) {
      await prefs.setString(_syncErrorKey, error.message);
    } catch (error) {
      await prefs.setString(_syncErrorKey, error.toString());
    }
  }

  Attendance? _localToday() {
    final raw = prefs.getString(_todayKey);
    if (raw == null) return null;
    final a = Attendance.fromJson(
      Map<String, dynamic>.from(jsonDecode(raw) as Map),
    );
    final n = AttendanceFormat.istNow();
    return a.date.year == n.year &&
            a.date.month == n.month &&
            a.date.day == n.day
        ? a
        : null;
  }

  String _duration(DateTime a, DateTime b) {
    final d = b.difference(a);
    return '${d.inHours}h ${d.inMinutes.remainder(60).toString().padLeft(2, '0')}m';
  }

  /// Mirrors backend AttendanceStatusCalculator for offline punch-out preview.
  /// Server remains authoritative after sync.
  String _statusFromDuration(DateTime punchIn, DateTime punchOut) {
    final minutes = punchOut.difference(punchIn).inMinutes;
    if (minutes >= 480) return 'Present';
    if (minutes >= 240) return 'Half Day';
    return 'Absent';
  }
}
