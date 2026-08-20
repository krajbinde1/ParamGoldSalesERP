import 'dart:io';

import 'package:flutter/services.dart';

class ApkInstallException implements Exception {
  const ApkInstallException(this.message);
  final String message;

  @override
  String toString() => message;
}

/// Native Android helpers for unknown-app install permission and APK install.
class ApkInstaller {
  static const _channel = MethodChannel('paramgold/app_update');

  Future<bool> canInstallPackages() async {
    if (!Platform.isAndroid) return false;
    try {
      final allowed = await _channel.invokeMethod<bool>('canInstallPackages');
      return allowed == true;
    } on PlatformException {
      return false;
    }
  }

  Future<void> openInstallPermissionSettings() async {
    if (!Platform.isAndroid) return;
    await _channel.invokeMethod<void>('openInstallPermissionSettings');
  }

  Future<void> installApk(String path) async {
    if (!Platform.isAndroid) {
      throw const ApkInstallException('APK updates are only supported on Android.');
    }
    try {
      await _channel.invokeMethod<void>('installApk', {'path': path});
    } on PlatformException catch (error) {
      throw ApkInstallException(
        error.message ?? 'Unable to open the Android installer.',
      );
    }
  }
}
