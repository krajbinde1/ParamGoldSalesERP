import 'package:dio/dio.dart';

import '../../../core/api/api_errors.dart';

class CompanyTransportApi {
  const CompanyTransportApi(this._dio);
  final Dio _dio;

  Map<String, dynamic> _body(Response response) =>
      Map<String, dynamic>.from(response.data as Map);

  dynamic _data(Response response) => _body(response)['data'];

  Future<Map<String, dynamic>> ledger({
    String? from,
    String? to,
    String? vehicleNo,
    String? orderNo,
    String? expenseType,
    int page = 1,
  }) async {
    try {
      final response = await _dio.get(
        '/production/company-transport/ledger',
        queryParameters: {
          if (from != null && from.isNotEmpty) 'from': from,
          if (to != null && to.isNotEmpty) 'to': to,
          if (vehicleNo != null && vehicleNo.isNotEmpty) 'vehicle_no': vehicleNo,
          if (orderNo != null && orderNo.isNotEmpty) 'order_no': orderNo,
          if (expenseType != null && expenseType.isNotEmpty)
            'expense_type': expenseType,
          'page': page,
          'per_page': 50,
        },
      );
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> entry(int id) async {
    try {
      final response =
          await _dio.get('/production/company-transport/entries/$id');
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<String?> uploadAttachment(String filePath) async {
    try {
      final formData = FormData.fromMap({
        'attachment': await MultipartFile.fromFile(filePath),
      });
      final response = await _dio.post(
        '/production/company-transport/expenses/attachment',
        data: formData,
      );
      final data = Map<String, dynamic>.from(_data(response) as Map);
      return data['attachment_path']?.toString();
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> createExpense(Map<String, dynamic> payload) async {
    try {
      final response = await _dio.post(
        '/production/company-transport/expenses',
        data: payload,
      );
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<List<Map<String, dynamic>>> listVehicles({String? search}) async {
    try {
      final response = await _dio.get(
        '/production/vehicles',
        queryParameters: search != null && search.trim().isNotEmpty
            ? {'search': search.trim()}
            : null,
      );
      final data = (response.data as Map)['data'];
      if (data is! List) return const [];
      return data
          .whereType<Map>()
          .map((item) => Map<String, dynamic>.from(item))
          .toList(growable: false);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<List<Map<String, dynamic>>> searchOrders(String search) async {
    try {
      final response = await _dio.get(
        '/production/company-transport/orders',
        queryParameters: {if (search.isNotEmpty) 'search': search},
      );
      final raw = _data(response);
      if (raw is! List) return const [];
      return raw
          .whereType<Map>()
          .map((row) => Map<String, dynamic>.from(row))
          .toList(growable: false);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }
}
