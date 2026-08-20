import 'package:dio/dio.dart';
import '../api/api_dio.dart';
import 'app_version_info.dart';

class AppUpdateApi {
  AppUpdateApi([Dio? dio]) : _dio = dio ?? ApiDio.create(logTag: 'AppVersion');

  final Dio _dio;

  Future<AppVersionInfo> fetch() async {
    final response = await _dio.get<Object>(
      '/app-version',
      options: Options(
        receiveTimeout: const Duration(seconds: 10),
        sendTimeout: const Duration(seconds: 10),
      ),
    );
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid app version response.',
      );
    }
    return AppVersionInfo.fromJson(Map<String, dynamic>.from(body));
  }
}
