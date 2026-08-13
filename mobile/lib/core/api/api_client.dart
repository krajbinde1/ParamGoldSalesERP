import 'package:dio/dio.dart';

import '../storage/session_store.dart';
import 'api_dio.dart';

class ApiClient {
  ApiClient(
    this._store, {
    this.onUnauthorized,
    this.clearSessionOnUnauthorized = true,
  }) {
    dio = ApiDio.create(logTag: 'API');
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await _store.token();
          if (token != null && token.isNotEmpty) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          handler.next(options);
        },
        onError: (error, handler) async {
          if (error.response?.statusCode == 401 && clearSessionOnUnauthorized) {
            await _store.clear();
            onUnauthorized?.call();
          }
          handler.next(error);
        },
      ),
    );
  }

  final SessionStore _store;
  final void Function()? onUnauthorized;
  /// When false, 401 responses do not clear the saved session (e.g. FCM register).
  final bool clearSessionOnUnauthorized;
  late final Dio dio;
}
