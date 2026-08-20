import 'dart:io';

import 'package:dio/dio.dart';
import '../../../core/api/api_errors.dart';

class DealerApplicationListResult {
  const DealerApplicationListResult({
    required this.rows,
    required this.counts,
  });

  final List<Map<String, dynamic>> rows;
  final Map<String, int> counts;

  factory DealerApplicationListResult.fromJson(Map<String, dynamic> json) {
    final applications = (json['data'] as List?)
            ?.map((item) => Map<String, dynamic>.from(item as Map))
            .toList() ??
        const <Map<String, dynamic>>[];
    final legacy = (json['legacy_dealers'] as List?)
            ?.map((item) => Map<String, dynamic>.from(item as Map))
            .toList() ??
        const <Map<String, dynamic>>[];
    final rawCounts = json['counts'] is Map
        ? Map<String, dynamic>.from(json['counts'] as Map)
        : const <String, dynamic>{};

    return DealerApplicationListResult(
      rows: [...applications, ...legacy],
      counts: {
        'draft': int.tryParse('${rawCounts['draft'] ?? 0}') ?? 0,
        'pending': int.tryParse('${rawCounts['pending'] ?? 0}') ?? 0,
        'approved': int.tryParse('${rawCounts['approved'] ?? 0}') ?? 0,
        'correction_required':
            int.tryParse('${rawCounts['correction_required'] ?? 0}') ?? 0,
        'rejected': int.tryParse('${rawCounts['rejected'] ?? 0}') ?? 0,
      },
    );
  }
}

class DealerApplicationApi {
  const DealerApplicationApi(this._dio);
  final Dio _dio;

  Future<DealerApplicationListResult> list({String? tab}) async {
    try {
      final response = await _dio.get(
        '/employee/dealer-applications',
        queryParameters: {if (tab != null) 'tab': tab},
      );
      return DealerApplicationListResult.fromJson(
        Map<String, dynamic>.from(response.data as Map),
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> getById(int id) async {
    try {
      final response = await _dio.get('/employee/dealer-applications/$id');
      return Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> save({
    int? id,
    required Map<String, dynamic> payload,
  }) async {
    try {
      final response = id == null
          ? await _dio.post('/employee/dealer-applications', data: payload)
          : await _dio.put('/employee/dealer-applications/$id', data: payload);
      return Map<String, dynamic>.from(response.data as Map);
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> submit(int id) async {
    try {
      final response = await _dio.post('/employee/dealer-applications/$id/submit');
      return Map<String, dynamic>.from(response.data as Map);
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> uploadDocument({
    required int applicationId,
    required String documentType,
    required String filePath,
  }) async {
    try {
      final formData = FormData.fromMap({
        'document_type': documentType,
        'file': await MultipartFile.fromFile(
          filePath,
          filename: File(filePath).uri.pathSegments.last,
        ),
      });
      final response = await _dio.post(
        '/employee/dealer-applications/$applicationId/documents',
        data: formData,
      );
      return Map<String, dynamic>.from(response.data as Map);
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }
}
