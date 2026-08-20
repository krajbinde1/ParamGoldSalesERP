import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';
import 'app_version_info.dart';

/// Persists a confirmed outdated build so a later API outage cannot bypass it.
class AppUpdateStore {
  static const _key = 'paramgold_confirmed_app_update';

  Future<AppVersionInfo?> readConfirmed() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_key);
    if (raw == null || raw.isEmpty) return null;
    try {
      final map = jsonDecode(raw);
      if (map is! Map) return null;
      return AppVersionInfo.fromJson(Map<String, dynamic>.from(map));
    } catch (_) {
      return null;
    }
  }

  Future<void> saveConfirmed(AppVersionInfo info) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key, jsonEncode(info.toJson()));
  }

  Future<void> clear() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_key);
  }
}
