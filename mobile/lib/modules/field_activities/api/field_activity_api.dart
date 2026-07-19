import 'dart:io';

import 'package:dio/dio.dart';
import '../models/field_activity_dashboard_data.dart';
import '../models/field_activity_detail.dart';

class FieldActivityApi {
  const FieldActivityApi(this._dio);
  final Dio _dio;

  Future<FieldActivityDashboardData> loadDashboard() async {
    final response = await _dio.get('/employee/field-activities');
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid field activity dashboard response.',
      );
    }

    return FieldActivityDashboardData.fromJson(Map<String, dynamic>.from(body));
  }

  Future<FieldActivityDetail> getActivity(int activityId) async {
    final response = await _dio.get('/employee/field-activities/$activityId');
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid field activity detail response.',
      );
    }

    final root = Map<String, dynamic>.from(body);
    final payload = root['data'] is Map
        ? Map<String, dynamic>.from(root['data'] as Map)
        : root;
    return FieldActivityDetail.fromJson(payload);
  }

  Future<String> submit({
    required String farmerName,
    required String village,
    required String taluka,
    required double latitude,
    required double longitude,
    required String photoPath,
  }) async {
    try {
      final formData = FormData.fromMap({
        'farmer_name': farmerName.trim(),
        'village': village.trim(),
        'taluka': taluka.trim(),
        'latitude': latitude,
        'longitude': longitude,
        'photo': await MultipartFile.fromFile(
          photoPath,
          filename: File(photoPath).uri.pathSegments.last,
        ),
      });

      final response = await _dio.post(
        '/employee/field-activities',
        data: formData,
      );
      final body = response.data;
      if (body is Map) {
        final message = body['message']?.toString();
        if (message != null && message.isNotEmpty) return message;
      }
      return 'Field activity submitted successfully.';
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
