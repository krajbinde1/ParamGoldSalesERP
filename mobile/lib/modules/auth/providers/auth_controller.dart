import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/scheduler.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/api/api_client.dart';
import '../../../core/auth/permission_service.dart';
import '../../../core/auth/user_role.dart';
import '../../../core/notifications/push_notification_service.dart';
import '../../../core/storage/session_store.dart';
import '../api/auth_api.dart';
import '../models/auth_session.dart';
import '../repository/auth_repository.dart';

class AuthController extends ChangeNotifier {
  AuthController() {
    final store = SessionStore();
    final client = ApiClient(store, onUnauthorized: sessionExpired);
    _repository = AuthRepository(AuthApi(client.dio), store);
    unawaited(initialize());
  }

  late final AuthRepository _repository;
  AuthSession? session;
  bool initializing = true;
  bool loading = false;
  String? message;

  bool get authenticated => session != null;
  bool get mustChangePassword => session?.user.mustChangePassword == true;
  UserRole get userRole =>
      UserRole.fromValue(session?.user.role ?? UserRole.employee.value);
  PermissionService get permissions => PermissionService(
    session?.user.permissions ?? const [],
    userRole,
  );
  bool get canAccessEmployeeWorkflow => userRole.canAccessEmployeeWorkflow();

  /// Defers listener notification when called during build/layout to avoid
  /// GoRouter/inherited-widget teardown races (`_dependents.isEmpty`).
  void _notify() {
    if (!hasListeners) return;

    final phase = SchedulerBinding.instance.schedulerPhase;
    if (phase == SchedulerPhase.idle ||
        phase == SchedulerPhase.postFrameCallbacks) {
      notifyListeners();
      return;
    }

    SchedulerBinding.instance.addPostFrameCallback((_) {
      if (hasListeners) notifyListeners();
    });
  }

  Future<void> initialize() async {
    initializing = true;
    message = null;
    _notify();
    try {
      final result = await _repository.restore().timeout(
        const Duration(seconds: 12),
        onTimeout: () async {
          AuthSession? cached;
          try {
            cached = await SessionStore().read().timeout(
              const Duration(seconds: 3),
            );
          } catch (_) {
            cached = null;
          }
          return AuthRestoreResult(
            session: cached,
            connectionError: AuthApiException(connectionFailureMessage()),
          );
        },
      );

      // Prefer cached/validated session so cold start always reaches a screen.
      session = result.session;
      if (result.connectionError != null) {
        message = _shortConnectionMessage(result.connectionError!.message);
      }
    } catch (error) {
      debugPrint('Auth initialize failed: $error');
      try {
        session = await SessionStore().read().timeout(const Duration(seconds: 3));
      } catch (_) {
        session = null;
      }
      message = connectionFailureMessage();
    } finally {
      initializing = false;
      _notify();
    }
  }

  Future<bool> login(String loginId, String password) async {
    if (loading) return false;

    loading = true;
    message = null;
    _notify();
    try {
      session = await _repository.login(loginId, password);
      debugPrint(
        'AuthController.login success '
        '(authenticated=$authenticated, role=${session?.user.role})',
      );
      // FCM/device-token registration runs via NotificationNavigator and must
      // never be awaited here — login succeeds regardless of push setup.
      return true;
    } catch (error, stackTrace) {
      debugPrint('AuthController.login failed: $error');
      debugPrintStack(stackTrace: stackTrace, label: 'AuthController.login');
      final raw = error is AuthApiException
          ? error.message
          : errorMessage(error);
      message = _friendlyLoginMessage(raw);
      return false;
    } finally {
      loading = false;
      _notify();
    }
  }

  Future<bool> changePassword(String currentPassword, String password) async {
    final current = session;
    if (current == null || loading) return false;

    loading = true;
    message = null;
    _notify();
    try {
      session = await _repository.changePassword(
        current,
        currentPassword,
        password,
      );
      return true;
    } catch (error) {
      message = error is AuthApiException
          ? _shortConnectionMessage(error.message)
          : _shortConnectionMessage(errorMessage(error));
      return false;
    } finally {
      loading = false;
      _notify();
    }
  }

  Future<void> logout() async {
    if (loading) return;

    loading = true;
    _notify();
    try {
      try {
        await PushNotificationService.instance.unregisterToken(
          store: SessionStore(),
          onUnauthorized: null,
        );
      } catch (_) {}
      await _repository.logout();
    } finally {
      session = null;
      loading = false;
      _notify();
    }
  }

  void sessionExpired() {
    if (!authenticated) return;

    session = null;
    loading = false;
    message = 'Session expired. Please login again.';
    _notify();
  }

  void clearMessage() {
    if (message == null) return;
    message = null;
    _notify();
  }

  String _shortConnectionMessage(String raw) {
    final lower = raw.toLowerCase();
    if (lower.contains('unable to connect') ||
        lower.contains('timed out') ||
        lower.contains('timeout') ||
        lower.contains('socket') ||
        lower.contains('network')) {
      return connectionFailureMessage();
    }
    return raw;
  }

  /// User-facing login failure copy (never expose raw server/API text).
  String _friendlyLoginMessage(String raw) {
    final shortened = _shortConnectionMessage(raw);
    final lower = shortened.toLowerCase();
    if (lower.contains('session expired')) return shortened;
    return 'Unable to login. Please check your credentials or connection.';
  }
}

class LoginValidators {
  static String? mobile(String? value) {
    if (value == null || value.isEmpty) return 'Mobile number is required.';
    if (!RegExp(r'^[6-9][0-9]{9}$').hasMatch(value)) {
      return 'Enter a valid 10-digit mobile number.';
    }
    return null;
  }

  static String? password(String? value) =>
      value == null || value.isEmpty ? 'Password is required.' : null;
}
