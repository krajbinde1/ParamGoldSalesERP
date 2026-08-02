import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../modules/auth/models/auth_session.dart';

class SessionStore {
  SessionStore([FlutterSecureStorage? storage])
    : _storage =
          storage ??
          const FlutterSecureStorage(
            // resetOnError avoids Keystore corruption loops that freeze launch.
            aOptions: AndroidOptions(resetOnError: true),
          );

  static const _sessionKey = 'employee_auth_session';
  static const _prefsSessionKey = 'employee_auth_session_prefs';
  static const _storageTimeout = Duration(seconds: 4);

  final FlutterSecureStorage _storage;

  Future<AuthSession?> read() async {
    try {
      final raw = await _storage
          .read(key: _sessionKey)
          .timeout(_storageTimeout);
      final session = _decode(raw);
      if (session != null) return session;
    } catch (error) {
      debugPrint('Secure session read failed: $error');
    }

    // Fallback when Keystore / secure storage hangs or fails on some devices.
    try {
      final preferences = await SharedPreferences.getInstance().timeout(
        _storageTimeout,
      );
      return _decode(preferences.getString(_prefsSessionKey));
    } catch (error) {
      debugPrint('Prefs session read failed: $error');
      return null;
    }
  }

  Future<void> write(AuthSession session) async {
    final encoded = jsonEncode(session.toJson());
    try {
      await _storage
          .write(key: _sessionKey, value: encoded)
          .timeout(_storageTimeout);
    } catch (error) {
      debugPrint('Secure session write failed: $error');
    }

    final preferences = await SharedPreferences.getInstance();
    await preferences.setString(_prefsSessionKey, encoded);
    await preferences.setString('login_token', session.token);
  }

  Future<String?> token() async => (await read())?.token;

  Future<void> clear() async {
    try {
      await _storage.delete(key: _sessionKey).timeout(_storageTimeout);
    } catch (error) {
      debugPrint('Secure session clear failed: $error');
    }

    final preferences = await SharedPreferences.getInstance();
    await preferences.remove(_prefsSessionKey);
    await preferences.remove('login_token');
    await preferences.remove('token');
  }

  AuthSession? _decode(String? raw) {
    if (raw == null || raw.isEmpty) return null;
    try {
      return AuthSession.fromJson(
        Map<String, dynamic>.from(jsonDecode(raw) as Map),
      );
    } catch (_) {
      return null;
    }
  }
}
