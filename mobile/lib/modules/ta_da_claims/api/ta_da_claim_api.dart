import 'dart:io';

import 'package:dio/dio.dart';
import '../models/ta_da_claim_calendar_data.dart';
import '../models/ta_da_claim_dashboard_data.dart';
import '../models/ta_da_claim_detail.dart';
import '../models/ta_da_travel_summary.dart';

class TaDaClaimApi {
  const TaDaClaimApi(this._dio);
  final Dio _dio;

  Future<TaDaClaimDashboardData> loadDashboard() async {
    final response = await _dio.get('/employee/ta-da-claims');
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid TA/DA claim dashboard response.',
      );
    }

    return TaDaClaimDashboardData.fromJson(Map<String, dynamic>.from(body));
  }

  Future<TaDaClaimCalendarData> loadCalendar({
    required int month,
    required int year,
  }) async {
    final response = await _dio.get(
      '/employee/ta-da-claims/calendar',
      queryParameters: {'month': month, 'year': year},
    );
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid TA/DA claim calendar response.',
      );
    }

    return TaDaClaimCalendarData.fromJson(Map<String, dynamic>.from(body));
  }

  Future<double> fetchPerKmRate() async {
    try {
      final response = await _dio.get('/employee/ta-da-rate');
      final body = response.data;
      if (body is! Map) {
        throw DioException(
          requestOptions: response.requestOptions,
          message: 'Invalid TA/DA rate response.',
        );
      }

      return double.tryParse('${body['per_km_rate'] ?? ''}') ?? 0;
    } on DioException catch (error) {
      throw _mapApiError(error);
    }
  }

  Future<TaDaClaimDetail> getClaim(int claimId) async {
    final response = await _dio.get('/employee/ta-da-claims/$claimId');
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid TA/DA claim detail response.',
      );
    }

    final root = Map<String, dynamic>.from(body);
    final payload = root['data'] is Map
        ? Map<String, dynamic>.from(root['data'] as Map)
        : root;
    return TaDaClaimDetail.fromJson(payload);
  }

  Future<TaDaTravelSummary> fetchTravelSummary({
    required DateTime claimDate,
  }) async {
    try {
      final response = await _dio.get(
        '/employee/ta-da-claims/travel-summary',
        queryParameters: {
          'claim_date': claimDate.toIso8601String().split('T').first,
        },
      );
      final body = response.data;
      if (body is! Map) {
        throw DioException(
          requestOptions: response.requestOptions,
          message: 'Invalid TA/DA travel summary response.',
        );
      }

      return TaDaTravelSummary.fromJson(Map<String, dynamic>.from(body));
    } on DioException catch (error) {
      throw _mapApiError(error);
    }
  }

  Future<String> submit({
    required DateTime claimDate,
    required String fromLocation,
    required String toLocation,
    required double daAmount,
    required double otherExpense,
    String? employeeRemarks,
    required String photoPath,
  }) async {
    try {
      final formData = FormData.fromMap({
        'claim_date': claimDate.toIso8601String().split('T').first,
        'from_location': fromLocation.trim(),
        'to_location': toLocation.trim(),
        'da_amount': daAmount,
        'other_expense': otherExpense,
        if (employeeRemarks != null && employeeRemarks.trim().isNotEmpty)
          'employee_remarks': employeeRemarks.trim(),
        'photo': await MultipartFile.fromFile(
          photoPath,
          filename: File(photoPath).uri.pathSegments.last,
        ),
      });

      final response = await _dio.post(
        '/employee/ta-da-claims',
        data: formData,
      );
      final body = response.data;
      if (body is Map) {
        final message = body['message']?.toString();
        if (message != null && message.isNotEmpty) return message;
      }
      return 'TA/DA claim submitted successfully.';
    } on DioException catch (error) {
      throw _mapApiError(error);
    }
  }

  DioException _mapApiError(DioException error) {
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
