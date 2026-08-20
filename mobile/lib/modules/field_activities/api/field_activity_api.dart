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

  Future<List<Map<String, dynamic>>> districts() async {
    final response = await _dio.get('/employee/field-activity-masters/districts');
    return _list(response.data);
  }

  Future<List<Map<String, dynamic>>> talukas(int districtId) async {
    final response = await _dio.get(
      '/employee/field-activity-masters/talukas',
      queryParameters: {'district_id': districtId},
    );
    return _list(response.data);
  }

  Future<List<Map<String, dynamic>>> crops({String? search}) async {
    final response = await _dio.get(
      '/employee/field-activity-masters/crops',
      queryParameters: {
        if (search != null && search.trim().isNotEmpty) 'search': search.trim(),
      },
    );
    return _list(response.data);
  }

  Future<Map<String, dynamic>?> lookupFarmer(String mobile) async {
    final response = await _dio.get(
      '/employee/farmers/lookup',
      queryParameters: {'mobile': mobile},
    );
    final body = response.data;
    if (body is! Map) return null;
    final root = Map<String, dynamic>.from(body);
    if (root['found'] != true) return null;
    return root;
  }

  Future<String> submit({
    required String farmerName,
    required String farmerMobile,
    required int districtId,
    required int talukaId,
    required String village,
    required int cropId,
    required double latitude,
    required double longitude,
    required String photoPath,
    required List<Map<String, dynamic>> recommendations,
    String? remark,
  }) async {
    try {
      final payload = <String, dynamic>{
        'farmer_name': farmerName.trim(),
        'farmer_mobile': farmerMobile.trim(),
        'district_id': districtId,
        'taluka_id': talukaId,
        'village': village.trim(),
        'crop_id': cropId,
        if (remark != null && remark.trim().isNotEmpty) 'remark': remark.trim(),
        'latitude': latitude,
        'longitude': longitude,
        'photo': await MultipartFile.fromFile(
          photoPath,
          filename: File(photoPath).uri.pathSegments.last,
        ),
      };

      for (var i = 0; i < recommendations.length; i++) {
        payload['recommendations[$i][product_id]'] =
            recommendations[i]['product_id'];
        final dosage = recommendations[i]['dosage']?.toString().trim() ?? '';
        final recRemark = recommendations[i]['remark']?.toString().trim() ?? '';
        if (dosage.isNotEmpty) {
          payload['recommendations[$i][dosage]'] = dosage;
        }
        if (recRemark.isNotEmpty) {
          payload['recommendations[$i][remark]'] = recRemark;
        }
      }

      final response = await _dio.post(
        '/employee/field-activities',
        data: FormData.fromMap(payload),
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

  List<Map<String, dynamic>> _list(Object? body) {
    if (body is! Map) return const [];
    final raw = body['data'];
    if (raw is! List) return const [];
    return raw
        .map((item) => Map<String, dynamic>.from(item as Map))
        .toList();
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
