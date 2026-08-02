import 'package:dio/dio.dart';
import '../../../core/api/api_errors.dart';

class PackagingMaterialInwardApi {
  const PackagingMaterialInwardApi(this._dio);
  final Dio _dio;

  Map<String, dynamic> _body(Response response) =>
      Map<String, dynamic>.from(response.data as Map);

  dynamic _data(Response response) => _body(response)['data'];

  Future<List<Map<String, dynamic>>> list({String? status, String? search}) async {
    try {
      final response = await _dio.get(
        '/production/packaging-inwards',
        queryParameters: {
          if (status != null && status.isNotEmpty) 'status': status,
          if (search != null && search.isNotEmpty) 'search': search,
        },
      );
      final data = Map<String, dynamic>.from(_data(response) as Map);
      return (data['items'] as List?)
              ?.map((e) => Map<String, dynamic>.from(e as Map))
              .toList() ??
          const [];
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> detail(int id) async {
    try {
      final response = await _dio.get('/production/packaging-inwards/$id');
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> createDraft(Map<String, dynamic> payload) async {
    try {
      final response =
          await _dio.post('/production/packaging-inwards', data: payload);
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> updateDraft(
    int id,
    Map<String, dynamic> payload,
  ) async {
    try {
      final response =
          await _dio.put('/production/packaging-inwards/$id', data: payload);
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> post(int id) async {
    try {
      final response =
          await _dio.post('/production/packaging-inwards/$id/post');
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> cancel(int id, {String? reason}) async {
    try {
      final response = await _dio.post(
        '/production/packaging-inwards/$id/cancel',
        data: {
          if (reason != null && reason.isNotEmpty) 'cancellation_reason': reason,
        },
      );
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<List<Map<String, dynamic>>> searchPackagingMaterials(String q) async {
    try {
      final response = await _dio.get(
        '/production/packaging-inwards/search/packaging-materials',
        queryParameters: {'q': q},
      );
      final data = Map<String, dynamic>.from(_data(response) as Map);
      return (data['items'] as List?)
              ?.map((e) => Map<String, dynamic>.from(e as Map))
              .toList() ??
          const [];
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<List<Map<String, dynamic>>> searchSuppliers(String q) async {
    try {
      final response = await _dio.get(
        '/production/packaging-inwards/search/suppliers',
        queryParameters: {'q': q},
      );
      final data = Map<String, dynamic>.from(_data(response) as Map);
      return (data['items'] as List?)
              ?.map((e) => Map<String, dynamic>.from(e as Map))
              .toList() ??
          const [];
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }
}
