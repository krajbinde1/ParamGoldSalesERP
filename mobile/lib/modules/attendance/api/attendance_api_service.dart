import 'dart:io';
import 'package:dio/dio.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../../core/api/api_dio.dart';
import '../../../core/api/api_errors.dart';
import '../models/attendance.dart';
import '../models/punch_capture.dart';

class AttendanceApiException implements Exception {
  const AttendanceApiException(this.message);
  final String message;
  @override
  String toString() => message;
}

class AttendanceApiService {
  AttendanceApiService(this._dio);
  final Dio _dio;
  static Future<AttendanceApiService> create() async {
    final prefs = await SharedPreferences.getInstance();
    final dio = ApiDio.create(logTag: 'Attendance API');
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (o, h) {
          final token =
              prefs.getString('login_token') ??
              prefs.getString('token') ??
              const String.fromEnvironment('ATTENDANCE_API_TOKEN');
          if (token.isNotEmpty) {
            o.headers['Authorization'] = 'Bearer $token';
          }
          h.next(o);
        },
      ),
    );
    return AttendanceApiService(dio);
  }

  Future<Attendance?> today() async {
    final d = await _get('/attendance/today');
    final raw = d['attendance'];
    return raw is Map
        ? Attendance.fromJson(Map<String, dynamic>.from(raw))
        : null;
  }

  Future<List<Attendance>> history(DateTime m) async {
    final a = DateTime(m.year, m.month), b = DateTime(m.year, m.month + 1, 0);
    final d = await _get(
      '/attendance/history',
      query: {'date_from': _date(a), 'date_to': _date(b)},
    );
    final items = d is List
        ? d
        : d is Map && d['data'] is List
        ? d['data'] as List
        : const <dynamic>[];
    return items
        .map((e) => Attendance.fromJson(Map<String, dynamic>.from(e as Map)))
        .toList();
  }

  Future<AttendanceMonthlySummary> monthlySummary(DateTime month) async {
    final d = await _get(
      '/attendance/monthly-summary',
      query: {'month': month.month, 'year': month.year},
    );
    final payload = d is Map<String, dynamic>
        ? d
        : d is Map
        ? Map<String, dynamic>.from(d)
        : <String, dynamic>{};
    return AttendanceMonthlySummary.fromJson(payload);
  }

  Future<Attendance> punch(String action, PunchCapture c) async {
    try {
      final f = FormData.fromMap({
        'latitude': c.latitude,
        'longitude': c.longitude,
        'location_address': c.address,
        'captured_at': c.capturedAt.toIso8601String(),
        'photo': await MultipartFile.fromFile(
          c.photoPath,
          filename: File(c.photoPath).uri.pathSegments.last,
        ),
      });
      final r = await _dio.post('/attendance/$action', data: f);
      return Attendance.fromJson(
        Map<String, dynamic>.from(r.data['data'] as Map),
      );
    } on DioException catch (e) {
      throw _error(e);
    }
  }

  // TEST ONLY - REMOVE BEFORE PRODUCTION
  Future<String> resetToday() async {
    try {
      final response = await _dio.post('/employee/attendance/reset-today');
      final body = response.data;
      if (body is Map) {
        final message = body['message']?.toString();
        if (message != null && message.isNotEmpty) return message;
      }
      return "Today's attendance has been reset successfully.";
    } on DioException catch (e) {
      throw _error(e);
    }
  }

  Future<dynamic> _get(String path, {Map<String, dynamic>? query}) async {
    try {
      final r = await _dio.get(path, queryParameters: query);
      return r.data['data'];
    } on DioException catch (e) {
      throw _error(e);
    }
  }

  AttendanceApiException _error(DioException e) {
    final b = e.response?.data;
    final m = b is Map ? b['message']?.toString() : null;
    if (isConnectionFailure(e)) {
      return AttendanceApiException(connectionFailureMessage());
    }
    return AttendanceApiException(
      m ?? 'Unable to complete the request. Please try again.',
    );
  }

  String _date(DateTime v) => v.toIso8601String().substring(0, 10);
}
