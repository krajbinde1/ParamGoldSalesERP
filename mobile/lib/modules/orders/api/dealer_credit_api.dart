import 'package:dio/dio.dart';

import '../../credit_limits/models/dealer_credit_status.dart';

class DealerCreditApi {
  const DealerCreditApi(this._dio);

  final Dio _dio;

  Future<DealerCreditStatus> check({
    required int dealerId,
    required double orderAmount,
    int? excludeOrderId,
  }) async {
    final response = await _dio.get(
      '/employee/dealers/$dealerId/credit-check',
      queryParameters: {
        'order_amount': orderAmount,
        'exclude_order_id': ?excludeOrderId,
      },
    );
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid credit limit response.',
      );
    }
    final root = Map<String, dynamic>.from(body);
    final payload = root['data'] is Map
        ? Map<String, dynamic>.from(root['data'] as Map)
        : root;
    return DealerCreditStatus.fromJson(payload);
  }
}
