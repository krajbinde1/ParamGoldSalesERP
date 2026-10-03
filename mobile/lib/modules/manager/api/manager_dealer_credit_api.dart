import 'package:dio/dio.dart';

import '../../credit_limits/models/dealer_credit_status.dart';

class ManagerDealerCreditApi {
  const ManagerDealerCreditApi(this._dio);

  final Dio _dio;

  Future<List<DealerCreditStatus>> list({String search = ''}) async {
    final response = await _dio.get(
      '/manager/dealer-credit-limits',
      queryParameters: {if (search.trim().isNotEmpty) 'search': search.trim()},
    );
    return _list(response);
  }

  Future<DealerCreditStatus> show(int dealerId) async {
    final response = await _dio.get('/manager/dealer-credit-limits/$dealerId');
    return _one(response);
  }

  Future<List<DealerCreditHistoryEntry>> history(int dealerId) async {
    final response = await _dio.get(
      '/manager/dealer-credit-limits/$dealerId/history',
    );
    final body = response.data;
    if (body is! Map || body['data'] is! List) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid credit limit history.',
      );
    }
    return (body['data'] as List)
        .whereType<Map>()
        .map(
          (item) => DealerCreditHistoryEntry.fromJson(
            Map<String, dynamic>.from(item),
          ),
        )
        .toList();
  }

  Future<DealerCreditStatus> setBase({
    required int dealerId,
    required double amount,
    required String remark,
  }) async {
    final response = await _dio.post(
      '/manager/dealer-credit-limits/$dealerId',
      data: {'amount': amount, 'remark': remark},
    );
    return _one(response);
  }

  Future<DealerCreditStatus> extend({
    required int dealerId,
    required double amount,
    required String validUntil,
    required String remark,
  }) async {
    final response = await _dio.post(
      '/manager/dealer-credit-limits/$dealerId/extend',
      data: {
        'amount': amount,
        'valid_until': validUntil,
        'remark': remark,
      },
    );
    return _one(response);
  }

  Future<DealerCreditStatus> expire({
    required int dealerId,
    required String remark,
  }) async {
    final response = await _dio.post(
      '/manager/dealer-credit-limits/$dealerId/expire-extension',
      data: {'remark': remark},
    );
    return _one(response);
  }

  List<DealerCreditStatus> _list(Response<dynamic> response) {
    final body = response.data;
    if (body is! Map || body['data'] is! List) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid credit limit list.',
      );
    }
    return (body['data'] as List)
        .whereType<Map>()
        .map(
          (item) =>
              DealerCreditStatus.fromJson(Map<String, dynamic>.from(item)),
        )
        .toList();
  }

  DealerCreditStatus _one(Response<dynamic> response) {
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
