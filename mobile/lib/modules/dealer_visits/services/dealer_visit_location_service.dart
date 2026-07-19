import 'package:geocoding/geocoding.dart';
import 'package:geolocator/geolocator.dart';

class DealerVisitLocationCapture {
  const DealerVisitLocationCapture({
    required this.latitude,
    required this.longitude,
    required this.accuracy,
    required this.capturedAt,
    this.summary,
  });

  final double latitude;
  final double longitude;
  final double accuracy;
  final DateTime capturedAt;
  final String? summary;
}

class DealerVisitLocationException implements Exception {
  const DealerVisitLocationException(this.message);
  final String message;

  @override
  String toString() => message;
}

class DealerVisitLocationService {
  static const _maxAge = Duration(minutes: 2);

  Future<DealerVisitLocationCapture> capture() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw const DealerVisitLocationException(
        'Please enable GPS to submit dealer visit.',
      );
    }

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      throw const DealerVisitLocationException(
        'Location permission is required. Enable it in app settings.',
      );
    }

    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        timeLimit: Duration(seconds: 20),
      ),
    );

    if (position.latitude.abs() < 0.000001 &&
        position.longitude.abs() < 0.000001) {
      throw const DealerVisitLocationException(
        'A valid GPS location could not be captured. Please try again outdoors.',
      );
    }

    final capturedAt = position.timestamp.toLocal();
    if (DateTime.now().difference(capturedAt) > _maxAge) {
      throw const DealerVisitLocationException(
        'GPS location is stale. Please capture location again.',
      );
    }

    String? summary;
    try {
      final placemark = (await placemarkFromCoordinates(
        position.latitude,
        position.longitude,
      )).first;
      summary = [
        placemark.locality,
        placemark.subLocality,
        placemark.administrativeArea,
      ].where((part) => part != null && part.isNotEmpty).join(', ');
      if (summary.isEmpty) summary = null;
    } catch (_) {
      summary = null;
    }

    return DealerVisitLocationCapture(
      latitude: position.latitude,
      longitude: position.longitude,
      accuracy: position.accuracy,
      capturedAt: capturedAt,
      summary: summary,
    );
  }
}
