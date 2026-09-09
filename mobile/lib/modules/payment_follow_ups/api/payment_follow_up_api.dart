import 'package:dio/dio.dart';

import '../../../core/api/api_errors.dart';
import '../models/payment_follow_up.dart';

class PaymentFollowUpApi {
  const PaymentFollowUpApi(this._dio);
  final Dio _dio;

  Future<PaymentFollowUpListData> list({String? search}) async {
    final response = await _dio.get(
      '/employee/payment-follow-ups',
      queryParameters: {
        if (search != null && search.trim().isNotEmpty) 'search': search.trim(),
      },
    );
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid payment follow-up list response.',
      );
    }

    return PaymentFollowUpListData.fromJson(Map<String, dynamic>.from(body));
  }

  Future<PaymentFollowUpDetail> show(int dealerId) async {
    final response = await _dio.get('/employee/payment-follow-ups/$dealerId');
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid payment follow-up detail response.',
      );
    }

    return PaymentFollowUpDetail.fromJson(Map<String, dynamic>.from(body));
  }

  Future<PaymentFollowUpDetail> addFollowUp({
    required int dealerId,
    required String remark,
    double? expectedAmount,
    required String nextFollowUpDate,
  }) async {
    try {
      final response = await _dio.post(
        '/employee/payment-follow-ups/$dealerId',
        data: {
          'remark': remark,
          if (expectedAmount != null) 'expected_amount': expectedAmount,
          'next_follow_up_date': nextFollowUpDate,
        },
      );
      final body = response.data;
      if (body is! Map) {
        throw DioException(
          requestOptions: response.requestOptions,
          message: 'Invalid payment follow-up save response.',
        );
      }

      return PaymentFollowUpDetail.fromJson(Map<String, dynamic>.from(body));
    } on DioException catch (error) {
      throw DioException(
        requestOptions: error.requestOptions,
        response: error.response,
        type: error.type,
        error: error.error,
        message: errorMessage(error),
      );
    }
  }
}
