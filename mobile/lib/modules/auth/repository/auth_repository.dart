import 'dart:async';

import 'package:flutter/foundation.dart';

import '../../../core/api/api_errors.dart';
import '../../../core/storage/session_store.dart';
import '../api/auth_api.dart';
import '../models/auth_session.dart';

class AuthRepository {
  const AuthRepository(this.api, this.store);
  final AuthApi api;
  final SessionStore store;

  static const restoreTimeout = Duration(seconds: 10);
  /// Login must outlive Dio connect/receive timeouts so we do not mask a
  /// successful API response as a credentials/connection failure.
  static const loginTimeout = Duration(seconds: 30);

  /// Restores a session from secure storage and validates it with `/me`.
  ///
  /// On connection failure, returns the cached session (if any) and attaches
  /// [AuthApiException] via [restoreConnectionError] so the UI can show
  /// "Unable to connect to server" without freezing.
  Future<AuthRestoreResult> restore() async {
    final stored = await store.read();
    if (stored == null) {
      return const AuthRestoreResult(session: null);
    }

    try {
      final fresh = await api.me(stored.token).timeout(restoreTimeout);
      await store.write(fresh);
      return AuthRestoreResult(session: fresh);
    } on TimeoutException {
      return AuthRestoreResult(
        session: stored,
        connectionError: AuthApiException(connectionFailureMessage()),
      );
    } on AuthApiException catch (error) {
      if (_isConnectionMessage(error.message)) {
        return AuthRestoreResult(session: stored, connectionError: error);
      }
      // Auth rejected (401/403/etc.) — clear stale credentials.
      await store.clear();
      return AuthRestoreResult(session: null, connectionError: error);
    } catch (_) {
      return AuthRestoreResult(
        session: stored,
        connectionError: AuthApiException(connectionFailureMessage()),
      );
    }
  }

  Future<AuthSession> login(String loginId, String password) async {
    try {
      final session = await api.login(loginId, password).timeout(loginTimeout);
      await store.write(session);
      debugPrint('AuthRepository.login session save completed');
      return session;
    } on TimeoutException {
      debugPrint('AuthRepository.login timed out after ${loginTimeout.inSeconds}s');
      throw AuthApiException(connectionFailureMessage());
    }
  }

  Future<AuthSession> changePassword(
    AuthSession session,
    String currentPassword,
    String password,
  ) async {
    final user = await api.changePassword(
      currentPassword: currentPassword,
      password: password,
    );
    final updated = session.copyWith(user: user);
    await store.write(updated);
    return updated;
  }

  Future<void> logout() async {
    try {
      await api.logout().timeout(restoreTimeout);
    } finally {
      await store.clear();
    }
  }

  bool _isConnectionMessage(String message) {
    final lower = message.toLowerCase();
    return lower.contains('unable to connect') ||
        lower.contains('timed out') ||
        lower.contains('timeout');
  }
}

class AuthRestoreResult {
  const AuthRestoreResult({required this.session, this.connectionError});

  final AuthSession? session;
  final AuthApiException? connectionError;
}
