import 'package:flutter/foundation.dart';
import 'package:flutter/scheduler.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/api/api_client.dart';
import '../../../core/auth/permission_service.dart';
import '../../../core/auth/user_role.dart';
import '../../../core/storage/session_store.dart';
import '../api/auth_api.dart';
import '../models/auth_session.dart';
import '../repository/auth_repository.dart';

class AuthController extends ChangeNotifier {
  AuthController() {
    final store = SessionStore();
    final client = ApiClient(store, onUnauthorized: sessionExpired);
    _repository = AuthRepository(AuthApi(client.dio), store);
    initialize();
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
    _notify();
    try {
      session = await _repository.restore();
    } catch (_) {
      session = null;
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
      return true;
    } catch (error) {
      message = error is AuthApiException
          ? error.message
          : errorMessage(error);
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
          ? error.message
          : errorMessage(error);
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
