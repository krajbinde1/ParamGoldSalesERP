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
  /// 1. `--dart-define=API_BASE_URL=https://example.com/api`
  /// 2. Release builds → [productionApiBaseUrl]
  /// 3. Debug builds → `--dart-define=API_HOST=<host>` or platform dev defaults
  static String get baseUrl {
    const fullOverride = String.fromEnvironment('API_BASE_URL');
    if (fullOverride.isNotEmpty) {
      return fullOverride;
    }

    if (kReleaseMode) {
      return productionApiBaseUrl;
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
      return '10.0.2.2';
    }

    // iOS simulator / desktop.
    return '127.0.0.1';
  }
}
