import 'package:dio/dio.dart';
import '../../../core/api/api_errors.dart';
import '../models/dealer_account.dart';

class DealerAccountApi {
  const DealerAccountApi(this._dio);
  final Dio _dio;

  Future<List<DealerAccountListItem>> list() async {
    try {
      final response = await _dio.get('/dealers');
      final body = response.data;
      if (body is! Map) return const [];
      final raw = body['data'];
      if (raw is! List) return const [];

      return raw
          .whereType<Map>()
          .map(
            (item) => DealerAccountListItem.fromJson(
              Map<String, dynamic>.from(item),
            ),
          )
          .where((dealer) => dealer.id > 0)
          .toList();
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<List<AssignedDealerListItem>> listAssigned() async {
    try {
      final response = await _dio.get('/employee/dealers');
      final body = response.data;
      if (body is! Map) return const [];
      final raw = body['data'];
      if (raw is! List) return const [];

      return raw
          .whereType<Map>()
          .map(
            (item) => AssignedDealerListItem.fromJson(
              Map<String, dynamic>.from(item),
            ),
          )
          .where((dealer) => dealer.id > 0 && dealer.firmName.isNotEmpty)
          .toList();
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<AssignedDealerListItem> showAssigned(int dealerId) async {
    try {
      final response = await _dio.get('/employee/dealers/$dealerId');
      return AssignedDealerListItem.fromJson(
        Map<String, dynamic>.from((response.data as Map)['data'] as Map),
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<AssignedDealerListItem> updateAssigned({
    required int dealerId,
    required Map<String, dynamic> payload,
  }) async {
    try {
      final response = await _dio.put(
        '/employee/dealers/$dealerId',
        data: payload,
      );
      return AssignedDealerListItem.fromJson(
        Map<String, dynamic>.from((response.data as Map)['data'] as Map),
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<DealerAccountDetail> show(int dealerId) async {
    try {
      final response = await _dio.get('/dealers/$dealerId');
      final data = Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
      return DealerAccountDetail.fromJson(data);
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<DealerAccountSummary> accountSummary(int dealerId) async {
    try {
      final response = await _dio.get('/dealers/$dealerId/account-summary');
      final data = Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
      return DealerAccountSummary.fromJson(data);
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<DealerLedgerData> ledger(int dealerId) async {
    try {
      final response = await _dio.get('/dealers/$dealerId/ledger');
      final data = Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
      return DealerLedgerData.fromJson(data);
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }
}
