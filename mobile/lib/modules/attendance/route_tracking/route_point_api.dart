import 'package:dio/dio.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../../core/api/api_dio.dart';
import '../../../core/api/api_errors.dart';
import 'models/route_point.dart';
import 'route_tracking_log.dart';

class RoutePointApiException implements Exception {
  const RoutePointApiException(this.message);
  final String message;

  @override
  String toString() => message;
}

class RoutePointApi {
  RoutePointApi(this._dio);

  final Dio _dio;

  static Future<RoutePointApi> create() async {
    final prefs = await SharedPreferences.getInstance();
    final dio = ApiDio.create(logTag: 'Route Point API');
    dio.options.headers['Content-Type'] = 'application/json';
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          final token =
              prefs.getString('login_token') ??
              prefs.getString('token') ??
              const String.fromEnvironment('ATTENDANCE_API_TOKEN');
          if (token.isNotEmpty) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          handler.next(options);
        },
      ),
    );
    return RoutePointApi(dio);
  }

  Future<void> uploadBatch({
    required int attendanceId,
    int? activeAttendanceId,
    required List<RoutePoint> points,
  }) async {
    if (points.isEmpty) return;
    final payload = {
      'attendance_id': attendanceId,
      'points': points.map((point) => point.toApiPayload()).toList(),
    };
    routeTrackingLog(
      'Upload request POST /employee/route-points/batch '
      'activeAttendanceId=$activeAttendanceId '
      'uploadedAttendanceId=$attendanceId points=${points.length} '
      'payload=$payload',
    );
    try {
      final response = await _dio.post(
        '/employee/route-points/batch',
        data: payload,
      );
      routeTrackingLog(
        'Upload response status=${response.statusCode} body=${response.data}',
      );
    } on DioException catch (error) {
      routeTrackingLog(
        'Upload failed status=${error.response?.statusCode} '
        'body=${error.response?.data}',
      );
      throw RoutePointApiException(_message(error));
    }
  }

  String _message(DioException error) {
    final body = error.response?.data;
    if (body is Map) {
      final errors = body['errors'];
      if (errors is Map && errors.isNotEmpty) {
        return errors.values
            .expand((value) => value is List ? value : [value])
            .map((value) => '$value')
            .join('\n');
      }
      final message = body['message']?.toString();
      if (message != null && message.isNotEmpty) return message;
    }
    if (isConnectionFailure(error)) {
      return connectionFailureMessage();
    }
    return 'Unable to upload route points. Please try again.';
  }
}
