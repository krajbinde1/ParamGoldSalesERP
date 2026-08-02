import 'dart:io';

import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:geolocator/geolocator.dart';
import 'package:permission_handler/permission_handler.dart' as ph;

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

  static Future<RouteTrackingPermissionResult> ensureForTracking() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      return const RouteTrackingPermissionResult(
        granted: false,
        message: 'Please enable GPS to start route tracking.',
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
        message:
            'Location permission is required for route tracking. Enable it in app settings.',
        permissionStatus: 'location_denied',
        guidance: setupGuidance,
      );
    }

    final notificationPermission =
        await FlutterForegroundTask.checkNotificationPermission();
    if (notificationPermission != NotificationPermission.granted) {
      await FlutterForegroundTask.requestNotificationPermission();
    }

    if (await ph.Permission.notification.isDenied) {
      await ph.Permission.notification.request();
    }

    final background = await ph.Permission.locationAlways.status;
    if (!background.isGranted) {
      final requested = await ph.Permission.locationAlways.request();
      if (!requested.isGranted) {
        return const RouteTrackingPermissionResult(
          granted: false,
          message:
              'Background location ("Allow all the time") is required to track your route while the screen is locked.',
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
              'Precise location is required for field route tracking. Enable Precise location in app settings.',
          permissionStatus: 'approximate_only',
          guidance: setupGuidance,
        );
      }

      if (!await FlutterForegroundTask.isIgnoringBatteryOptimizations) {
        await FlutterForegroundTask.requestIgnoreBatteryOptimization();
      }
    }

    return const RouteTrackingPermissionResult(
      granted: true,
      message: 'Route Tracking Active',
      permissionStatus: 'granted',
      guidance: setupGuidance,
    );
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
