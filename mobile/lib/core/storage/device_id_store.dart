import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';

/// Stable app-installation ID for single-device session binding.
/// Not IMEI / hardware serial — a generated UUID stored locally.
class DeviceIdStore {
  DeviceIdStore([FlutterSecureStorage? storage])
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(resetOnError: true),
            );

  static const _secureKey = 'paramgold_installation_id';
  static const _prefsKey = 'paramgold_installation_id_prefs';

  final FlutterSecureStorage _storage;
  String? _cached;

  Future<String> getOrCreate() async {
    if (_cached != null && _cached!.isNotEmpty) return _cached!;

    try {
      final existing = await _storage.read(key: _secureKey);
      if (existing != null && existing.trim().isNotEmpty) {
        _cached = existing.trim();
        await _mirrorPrefs(_cached!);
        return _cached!;
      }
    } catch (error) {
      debugPrint('DeviceIdStore secure read failed: $error');
    }

    try {
      final prefs = await SharedPreferences.getInstance();
      final existing = prefs.getString(_prefsKey);
      if (existing != null && existing.trim().isNotEmpty) {
        _cached = existing.trim();
        try {
          await _storage.write(key: _secureKey, value: _cached);
        } catch (_) {}
        return _cached!;
      }
    } catch (error) {
      debugPrint('DeviceIdStore prefs read failed: $error');
    }

    final created = const Uuid().v4();
    _cached = created;
    try {
      await _storage.write(key: _secureKey, value: created);
    } catch (error) {
      debugPrint('DeviceIdStore secure write failed: $error');
    }
    await _mirrorPrefs(created);
    return created;
  }

  Future<void> _mirrorPrefs(String value) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_prefsKey, value);
    } catch (_) {}
  }
}
