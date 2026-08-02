import 'dart:io';

import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:geolocator/geolocator.dart';
import 'package:permission_handler/permission_handler.dart' as ph;
import 'package:shared_preferences/shared_preferences.dart';

class RouteTrackingPermissionResult {
  const RouteTrackingPermissionResult({
    required this.granted,
    required this.message,
    this.permissionStatus = 'unknown',
    this.guidance,
  });

  final bool granted;
  final String message;
  final String permissionStatus;
  final String? guidance;
}

class RouteTrackingPermissions {
  static const setupGuidance =
      'For reliable route tracking, set Location to "Allow all the time", '
      'enable Precise location, set Battery usage to Unrestricted, and allow '
      'Background activity for ParamGold.';

  static const _batteryPromptedKey = 'route_tracking_battery_opt_prompted';

  /// Punch-in permission flow. Requests runtime permissions once as needed.
  /// Does not open app settings repeatedly.
  static Future<RouteTrackingPermissionResult> ensureForTracking() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      return const RouteTrackingPermissionResult(
        granted: false,
        message: 'GPS disabled',
        permissionStatus: 'gps_disabled',
        guidance: setupGuidance,
      );
    }

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      return const RouteTrackingPermissionResult(
        granted: false,
        message: 'Location permission denied',
        permissionStatus: 'location_denied',
        guidance: setupGuidance,
      );
    }

    if (Platform.isAndroid) {
      final notificationPermission =
          await FlutterForegroundTask.checkNotificationPermission();
      if (notificationPermission != NotificationPermission.granted) {
        await FlutterForegroundTask.requestNotificationPermission();
      }
      final afterNotify =
          await FlutterForegroundTask.checkNotificationPermission();
      if (afterNotify != NotificationPermission.granted) {
        return const RouteTrackingPermissionResult(
          granted: false,
          message: 'Notification permission denied',
          permissionStatus: 'notification_denied',
          guidance: setupGuidance,
        );
      }
    }

    final background = await ph.Permission.locationAlways.status;
    if (!background.isGranted) {
      final requested = await ph.Permission.locationAlways.request();
      if (!requested.isGranted) {
        return const RouteTrackingPermissionResult(
          granted: false,
          message: 'Background permission denied',
          permissionStatus: 'background_denied',
          guidance: setupGuidance,
        );
      }
    }

    if (Platform.isAndroid) {
      final accuracy = await Geolocator.getLocationAccuracy();
      if (accuracy == LocationAccuracyStatus.reduced) {
        return const RouteTrackingPermissionResult(
          granted: false,
          message:
              'Precise location is required. Enable Precise location in app settings.',
          permissionStatus: 'approximate_only',
          guidance: setupGuidance,
        );
      }

      // Prompt battery exemption at most once unless already unrestricted.
      if (!await FlutterForegroundTask.isIgnoringBatteryOptimizations) {
        final prefs = await SharedPreferences.getInstance();
        final prompted = prefs.getBool(_batteryPromptedKey) ?? false;
        if (!prompted) {
          await FlutterForegroundTask.requestIgnoreBatteryOptimization();
          await prefs.setBool(_batteryPromptedKey, true);
        }
      }
    }

    return const RouteTrackingPermissionResult(
      granted: true,
      message: 'Route Tracking Active',
      permissionStatus: 'granted',
      guidance: setupGuidance,
    );
  }

  /// Diagnose why tracking may be stopped without opening settings.
  static Future<String> diagnoseStoppedReason({
    required bool sessionActive,
    required bool serviceRunning,
    required bool hasToken,
  }) async {
    if (!sessionActive) {
      return 'No active attendance found';
    }
    if (!hasToken) {
      return 'Authentication token unavailable';
    }
    if (!await Geolocator.isLocationServiceEnabled()) {
      return 'GPS disabled';
    }
    final permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      return 'Location permission denied';
    }
    final always = await ph.Permission.locationAlways.status;
    if (!always.isGranted) {
      return 'Background permission denied';
    }
    if (Platform.isAndroid) {
      final notificationPermission =
          await FlutterForegroundTask.checkNotificationPermission();
      if (notificationPermission != NotificationPermission.granted) {
        return 'Notification permission denied';
      }
      final accuracy = await Geolocator.getLocationAccuracy();
      if (accuracy == LocationAccuracyStatus.reduced) {
        return 'Precise location disabled';
      }
      if (!await FlutterForegroundTask.isIgnoringBatteryOptimizations) {
        return 'Battery restriction detected';
      }
      if (!serviceRunning) {
        return 'Service killed by Android';
      }
    }
    if (!serviceRunning) {
      return 'Foreground service failed to start';
    }
    return 'Route tracking stopped';
  }

  static Future<String> currentPermissionLabel() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      return 'GPS off';
    }
    final permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      return 'Location denied';
    }
    final always = await ph.Permission.locationAlways.status;
    if (!always.isGranted) {
      return 'Background not allowed';
    }
    if (Platform.isAndroid) {
      final accuracy = await Geolocator.getLocationAccuracy();
      if (accuracy == LocationAccuracyStatus.reduced) {
        return 'Approximate only';
      }
      if (!await FlutterForegroundTask.isIgnoringBatteryOptimizations) {
        return 'Battery optimised';
      }
    }
    return 'OK';
  }

  static Future<String> currentGpsLabel() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      return 'GPS off';
    }
    return 'GPS on';
  }
}
