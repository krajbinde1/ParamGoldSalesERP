import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import 'api_config.dart';

class ApiForbiddenException implements Exception {
  const ApiForbiddenException([
    this.message = 'You do not have permission to perform this action.',
  ]);

  final String message;

  @override
  String toString() => message;
}

class SessionReplacedException implements Exception {
  const SessionReplacedException([
    this.message =
        'You have been logged out because your account was signed in on another device.',
  ]);

  static const code = 'SESSION_REPLACED';
  static const userMessage =
      'You have been logged out because your account was signed in on another device.';

  final String message;

  @override
  String toString() => message;
}

bool isSessionReplacedResponse(Object? data) {
  if (data is! Map) return false;
  final code = data['code']?.toString().trim().toUpperCase();
  if (code == SessionReplacedException.code) return true;
  final message = data['message']?.toString().toLowerCase() ?? '';
  return message.contains('signed in on another device');
}

bool isConnectionFailure(DioException error) {
  return error.response == null &&
      (error.type == DioExceptionType.connectionError ||
          error.type == DioExceptionType.connectionTimeout ||
          error.type == DioExceptionType.sendTimeout ||
          error.type == DioExceptionType.receiveTimeout ||
          (error.type == DioExceptionType.unknown && error.error is SocketException));
}

String connectionFailureMessage({String prefix = 'Unable to connect to server'}) {
  // Keep the user-facing copy short so offline / ANR-recovery paths stay clear.
  // Debug builds append the resolved base URL to help local development.
  if (kReleaseMode) {
    return prefix;
  }

  return '$prefix (${ApiConfig.baseUrl}). '
      'Ensure the backend is running and your device is on the same Wi‑Fi network.';
}

DioException mapApiError(DioException error) {
  if (error.response?.statusCode == 401 &&
      isSessionReplacedResponse(error.response?.data)) {
    return DioException(
      requestOptions: error.requestOptions,
      response: error.response,
      error: const SessionReplacedException(),
      message: SessionReplacedException.userMessage,
    );
  }

  if (error.response?.statusCode == 403) {
    final data = error.response?.data;
    final message = data is Map ? data['message']?.toString() : null;
    return DioException(
      requestOptions: error.requestOptions,
      response: error.response,
      error: ApiForbiddenException(
        message ?? 'You do not have permission to perform this action.',
      ),
      message: message ?? 'You do not have permission to perform this action.',
    );
  }

  final data = error.response?.data;
  if (data is Map) {
    final message = data['message']?.toString();
    final errors = data['errors'];
    if (errors is Map && errors.isNotEmpty) {
      final details = errors.values
          .expand((value) => value is List ? value : [value])
          .map((value) => '$value')
          .join('\n');
      return DioException(
        requestOptions: error.requestOptions,
        response: error.response,
        message: details,
      );
    }
    if (message != null && message.isNotEmpty) {
      return DioException(
        requestOptions: error.requestOptions,
        response: error.response,
        message: message,
      );
    }
  }

  return error;
}

String errorMessage(Object? error) {
  if (error is SessionReplacedException) return error.message;
  if (error is ApiForbiddenException) return error.message;
  if (error is DioException) {
    if (error.error is SessionReplacedException) {
      return (error.error as SessionReplacedException).message;
    }
    if (error.error is ApiForbiddenException) {
      return (error.error as ApiForbiddenException).message;
    }
    if (isConnectionFailure(error)) {
      return connectionFailureMessage();
    }
    if (error.response?.statusCode == 401 &&
        isSessionReplacedResponse(error.response?.data)) {
      return SessionReplacedException.userMessage;
    }
    return error.message ?? '$error';
  }
  return '$error';
}
