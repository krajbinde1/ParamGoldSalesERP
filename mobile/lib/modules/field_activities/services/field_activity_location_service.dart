import 'package:geolocator/geolocator.dart';

class FieldActivityLocation {
  const FieldActivityLocation({
    required this.latitude,
    required this.longitude,
  });

  final double latitude;
  final double longitude;
}

class FieldActivityLocationException implements Exception {
  const FieldActivityLocationException(this.message);
  final String message;

  @override
  String toString() => message;
}

class FieldActivityLocationService {
  Future<FieldActivityLocation> capture() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw const FieldActivityLocationException(
        'Please enable GPS to submit field activity.',
      );
    }

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      throw const FieldActivityLocationException(
        'Location permission is required. Enable it in app settings.',
      );
    }

    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        timeLimit: Duration(seconds: 20),
      ),
    );

    return FieldActivityLocation(
      latitude: position.latitude,
      longitude: position.longitude,
    );
  }
}
