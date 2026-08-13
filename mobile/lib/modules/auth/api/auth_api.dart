import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../../../core/api/api_errors.dart';
import '../models/auth_session.dart';

class AuthApiException implements Exception {
  const AuthApiException(this.message);
  final String message;
  @override
  String toString() => message;
}

class AuthApi {
  const AuthApi(this._dio);
  final Dio _dio;

  Future<AuthSession> login(String loginId, String password) async {
    try {
      final response = await _dio.post(
        '/login',
        data: {'login_id': loginId, 'password': password},
      );
      debugPrint('AuthApi.login HTTP status=${response.statusCode}');
      final data = response.data;
      if (data is! Map) {
        throw const AuthApiException('Unexpected login response format.');
      }
      final session = AuthSession.fromJson(Map<String, dynamic>.from(data));
      debugPrint(
        'AuthApi.login AuthSession parsed OK '
        '(userId=${session.user.id}, role=${session.user.role}, '
        'tokenLen=${session.token.length})',
      );
      return session;
    } on DioException catch (error) {
      debugPrint(
        'AuthApi.login DioException status=${error.response?.statusCode} '
        'type=${error.type}',
      );
      throw _exception(error);
    }
  }

  Future<AuthSession> me(String token) async {
    try {
      final response = await _dio.get('/me');
      final body = Map<String, dynamic>.from(response.data);
      return AuthSession.fromJson({...body, 'token': token});
    } on DioException catch (error) {
      throw _exception(error);
    }
  }

  Future<AuthUser> changePassword({
    required String currentPassword,
    required String password,
  }) async {
    try {
      final response = await _dio.post(
        '/change-password',
        data: {
          'current_password': currentPassword,
          'password': password,
          'password_confirmation': password,
        },
      );
      return AuthUser.fromJson(
        Map<String, dynamic>.from(response.data['user'] as Map),
      );
    } on DioException catch (error) {
      throw _exception(error);
    }
  }

  Future<void> logout() async {
    try {
      await _dio.post('/logout');
    } on DioException catch (error) {
      if (error.response?.statusCode != 401) throw _exception(error);
    }
  }

  AuthApiException _exception(DioException error) {
    final body = error.response?.data;
    final message = body is Map ? body['message']?.toString() : null;
    if (error.type == DioExceptionType.connectionTimeout ||
        error.type == DioExceptionType.sendTimeout ||
        error.type == DioExceptionType.receiveTimeout) {
      return AuthApiException(connectionFailureMessage());
    }
    if (isConnectionFailure(error)) {
      return AuthApiException(connectionFailureMessage());
    }
    if (error.response?.statusCode == 401) {
      return const AuthApiException('Session expired. Please login again.');
    }
    if (error.response?.statusCode == 403) {
      return const AuthApiException('Employee account is inactive');
    }
    if (error.response?.statusCode == 422) {
      return const AuthApiException('Invalid mobile number or password');
    }
    return AuthApiException(message ?? connectionFailureMessage());
  }
}
