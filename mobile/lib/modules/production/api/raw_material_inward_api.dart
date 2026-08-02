import 'package:dio/dio.dart';
import '../../../core/api/api_errors.dart';

class RawMaterialInwardApi {
  const RawMaterialInwardApi(this._dio);
  final Dio _dio;

  Map<String, dynamic> _body(Response response) =>
      Map<String, dynamic>.from(response.data as Map);

  dynamic _data(Response response) => _body(response)['data'];

  Future<List<Map<String, dynamic>>> list({String? status, String? search}) async {
    try {
      final response = await _dio.get(
        '/production/inwards',
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
      final response = await _dio.get('/production/inwards/$id');
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> createDraft(Map<String, dynamic> payload) async {
    try {
      final response = await _dio.post('/production/inwards', data: payload);
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
      final response = await _dio.put('/production/inwards/$id', data: payload);
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> post(int id) async {
    try {
      final response = await _dio.post('/production/inwards/$id/post');
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<List<Map<String, dynamic>>> searchRawMaterials(String q) async {
    try {
      final response = await _dio.get(
        '/production/inwards/search/raw-materials',
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
        '/production/inwards/search/suppliers',
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

  Future<String?> uploadAttachment(String filePath) async {
    try {
      final formData = FormData.fromMap({
        'attachment': await MultipartFile.fromFile(filePath),
      });
      final response = await _dio.post(
        '/production/inwards/attachment',
        data: formData,
      );
      final data = Map<String, dynamic>.from(_data(response) as Map);
      return data['attachment_path']?.toString();
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }
}
