import 'dart:developer' as developer;

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import 'api_config.dart';

/// Shared Dio factory — all API clients should use this for consistent
/// base URL, timeouts, and debug logging.
class ApiDio {
  static Dio create({String logTag = 'API'}) {
    final dio = Dio(
      BaseOptions(
        baseUrl: ApiConfig.baseUrl,
        connectTimeout: const Duration(seconds: 10),
        receiveTimeout: const Duration(seconds: 15),
        sendTimeout: const Duration(seconds: 15),
        headers: {'Accept': 'application/json'},
      ),
    );

    if (kDebugMode) {
      dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (options, handler) {
            developer.log(
              '→ ${options.method} ${options.uri}',
              name: logTag,
            );
            handler.next(options);
          },
          onResponse: (response, handler) {
            developer.log(
              '← ${response.statusCode} ${response.requestOptions.uri}',
              name: logTag,
            );
            handler.next(response);
          },
          onError: (error, handler) {
            final statusCode = error.response?.statusCode;
            developer.log(
              statusCode == null
                  ? '✗ ${error.requestOptions.uri}'
                  : '✗ $statusCode ${error.requestOptions.uri}',
              name: logTag,
            );
            handler.next(error);
          },
        ),
      );
    }

    return dio;
  }
}
