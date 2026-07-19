import 'dart:io';

import 'package:dio/dio.dart';
import '../models/dealer_visit_dashboard_data.dart';
import '../models/dealer_visit_detail.dart';

class DealerVisitApi {
  const DealerVisitApi(this._dio);
  final Dio _dio;

  Future<DealerVisitDashboardData> loadDashboard() async {
    final response = await _dio.get('/employee/dealer-visits');
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid dealer visit dashboard response.',
      );
    }

    return DealerVisitDashboardData.fromJson(Map<String, dynamic>.from(body));
  }

  Future<DealerVisitDetail> getVisit(int visitId) async {
    final response = await _dio.get('/employee/dealer-visits/$visitId');
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid dealer visit detail response.',
      );
    }

    final root = Map<String, dynamic>.from(body);
    final payload = root['data'] is Map
        ? Map<String, dynamic>.from(root['data'] as Map)
        : root;
    return DealerVisitDetail.fromJson(payload);
  }

  Future<String> submit({
    required int dealerId,
    required double latitude,
    required double longitude,
    required double accuracy,
    required DateTime locationCapturedAt,
    required String photoPath,
  }) async {
    try {
      final formData = FormData.fromMap({
        'dealer_id': dealerId,
        'latitude': latitude,
        'longitude': longitude,
        'accuracy': accuracy,
        'location_captured_at': locationCapturedAt.toIso8601String(),
        'photo': await MultipartFile.fromFile(
          photoPath,
          filename: File(photoPath).uri.pathSegments.last,
        ),
      });

      final response = await _dio.post(
        '/employee/dealer-visits',
        data: formData,
      );
      final body = response.data;
      if (body is Map) {
        final message = body['message']?.toString();
        if (message != null && message.isNotEmpty) return message;
      }
      return 'Dealer visit submitted successfully.';
    } on DioException catch (error) {
      throw _mapSubmitError(error);
    }
  }

  DioException _mapSubmitError(DioException error) {
    final data = error.response?.data;
    if (data is Map) {
      final message = data['message']?.toString();
      final errors = data['errors'];
      if (errors is Map && errors.isNotEmpty) {
        final details = errors.values
            .expand((value) => value is List ? value : [value])
            .map((value) => '$value')
            .join('\n');
        return DioException(
          requestOptions: error.requestOptions,
          response: error.response,
          message: details,
        );
      }
      if (message != null && message.isNotEmpty) {
        return DioException(
          requestOptions: error.requestOptions,
          response: error.response,
          message: message,
        );
      }
    }
    return error;
  }
}
