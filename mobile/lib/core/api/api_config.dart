import 'dart:io';

import 'package:flutter/foundation.dart';

/// Production API base URL used by release builds.
const String productionApiBaseUrl = 'https://erp.paramgold.in/api';

class ApiConfig {
  static const int devPort = 8000;
  static const String devPath = '/api';

  /// Resolves the API base URL for the current build.
  ///
  /// Priority:
  /// 1. Release builds → always [productionApiBaseUrl]
  /// 2. Debug `--dart-define=API_BASE_URL=https://example.com/api`
  /// 3. Debug `--dart-define=API_HOST=<host>` or platform dev defaults
  static String get baseUrl {
    if (kReleaseMode) {
      return productionApiBaseUrl;
    }

    const fullOverride = String.fromEnvironment('API_BASE_URL');
    if (fullOverride.isNotEmpty) {
      return fullOverride;
    }

    const hostOverride = String.fromEnvironment('API_HOST');
    final host = hostOverride.isNotEmpty
        ? hostOverride
        : _defaultDevHostForPlatform();

    return 'http://$host:$devPort$devPath';
  }

  static String _defaultDevHostForPlatform() {
    if (kIsWeb) {
      return '127.0.0.1';
    }

    if (Platform.isAndroid) {
      // Android emulator alias for the host machine.
      return '192.168.1.7';
    }

    // iOS simulator / desktop.
    return '127.0.0.1';
  }
}
