import 'dart:io';

import 'package:dio/dio.dart';
import '../models/collection_dashboard_data.dart';
import '../models/collection_detail.dart';

class CollectionApi {
  const CollectionApi(this._dio);
  final Dio _dio;

  Future<CollectionDashboardData> loadDashboard() async {
    final response = await _dio.get('/employee/collections');
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid collection dashboard response.',
      );
    }

    final root = Map<String, dynamic>.from(body);
    final payload = root['data'] is Map
        ? Map<String, dynamic>.from(root['data'] as Map)
        : root;
    return CollectionDashboardData.fromJson(payload);
  }

  Future<CollectionDetail> getCollection(int collectionId) async {
    final response = await _dio.get('/employee/collections/$collectionId');
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid collection detail response.',
      );
    }

    final root = Map<String, dynamic>.from(body);
    final payload = root['data'] is Map
        ? Map<String, dynamic>.from(root['data'] as Map)
        : root;
    return CollectionDetail.fromJson(payload);
  }

  Future<String> submit({
    required int dealerId,
    required double amount,
    required DateTime collectionDate,
    required String photoPath,
    String? remarks,
  }) async {
    try {
      final formData = FormData.fromMap({
        'dealer_id': dealerId,
        'amount': amount,
        'collection_date': _formatDate(collectionDate),
        if (remarks != null && remarks.trim().isNotEmpty)
          'remarks': remarks.trim(),
        'photo': await MultipartFile.fromFile(
          photoPath,
          filename: File(photoPath).uri.pathSegments.last,
        ),
      });

      final response = await _dio.post('/employee/collections', data: formData);
      final body = response.data;
      if (body is Map) {
        final message = body['message']?.toString();
        if (message != null && message.isNotEmpty) return message;
      }
      return 'Collection submitted successfully.';
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

  String _formatDate(DateTime date) =>
      '${date.year.toString().padLeft(4, '0')}-'
      '${date.month.toString().padLeft(2, '0')}-'
      '${date.day.toString().padLeft(2, '0')}';
}
