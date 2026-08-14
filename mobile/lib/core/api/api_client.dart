import 'package:dio/dio.dart';

import '../storage/device_id_store.dart';
import '../storage/session_store.dart';
import 'api_dio.dart';
import 'api_errors.dart';

class ApiClient {
  ApiClient(
    this._store, {
    this.onUnauthorized,
    this.onSessionReplaced,
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
          try {
            final deviceId = await DeviceIdStore().getOrCreate();
            options.headers['X-Device-Id'] = deviceId;
          } catch (_) {}
          handler.next(options);
        },
        onError: (error, handler) async {
          if (error.response?.statusCode == 401 &&
              clearSessionOnUnauthorized) {
            final replaced = isSessionReplacedResponse(error.response?.data);
            await _store.clear();
            if (replaced) {
              onSessionReplaced?.call();
            } else {
              onUnauthorized?.call();
            }
          }
          handler.next(error);
        },
      ),
    );
  }

  final SessionStore _store;
  final void Function()? onUnauthorized;
  final void Function()? onSessionReplaced;

  /// When false, 401 responses do not clear the saved session (e.g. FCM register).
  final bool clearSessionOnUnauthorized;
  late final Dio dio;
}
