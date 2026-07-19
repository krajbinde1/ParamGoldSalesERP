import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../modules/auth/models/auth_session.dart';

class SessionStore {
  SessionStore([FlutterSecureStorage? storage])
    : _storage = storage ?? const FlutterSecureStorage();

  static const _sessionKey = 'employee_auth_session';
  final FlutterSecureStorage _storage;

  Future<AuthSession?> read() async {
    final raw = await _storage.read(key: _sessionKey);
    if (raw == null) return null;
    try {
      return AuthSession.fromJson(
        Map<String, dynamic>.from(jsonDecode(raw) as Map),
      );
    } catch (_) {
      await clear();
      return null;
    }
  }

  Future<void> write(AuthSession session) async {
    await _storage.write(key: _sessionKey, value: jsonEncode(session.toJson()));
    final preferences = await SharedPreferences.getInstance();
    await preferences.setString('login_token', session.token);
  }

  Future<String?> token() async => (await read())?.token;

  Future<void> clear() async {
    await _storage.delete(key: _sessionKey);
    final preferences = await SharedPreferences.getInstance();
    await preferences.remove('login_token');
    await preferences.remove('token');
  }
}
