import 'package:geolocator/geolocator.dart';
import 'package:permission_handler/permission_handler.dart' as ph;

class RouteTrackingPermissionResult {
  const RouteTrackingPermissionResult({
    required this.granted,
    required this.message,
  });

  final bool granted;
  final String message;
}

class RouteTrackingPermissions {
  static Future<RouteTrackingPermissionResult> ensureForTracking() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      return const RouteTrackingPermissionResult(
        granted: false,
        message: 'Please enable GPS to start route tracking.',
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
      );
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
              'Background location permission is required to track your route while the app is in the background. Enable "Allow all the time" in app settings.',
        );
      }
    }

    return const RouteTrackingPermissionResult(
      granted: true,
      message: 'Route tracking active',
    );
  }
}
