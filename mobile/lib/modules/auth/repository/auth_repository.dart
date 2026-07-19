import '../../../core/storage/session_store.dart';
import '../api/auth_api.dart';
import '../models/auth_session.dart';

class AuthRepository {
  const AuthRepository(this.api, this.store);
  final AuthApi api;
  final SessionStore store;

  Future<AuthSession?> restore() async {
    final stored = await store.read();
    if (stored == null) return null;
    final fresh = await api.me(stored.token);
    await store.write(fresh);
    return fresh;
  }

  Future<AuthSession> login(String loginId, String password) async {
    final session = await api.login(loginId, password);
    await store.write(session);
    return session;
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
      await api.logout();
    } finally {
      await store.clear();
    }
  }
}
