import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

double? parseCapturedCoordinate(Object? value) {
  if (value == null) return null;
  if (value is num) return value.toDouble();
  final parsed = double.tryParse(value.toString().trim());
  return parsed;
}

bool hasCapturedCoordinates({
  Object? latitude,
  Object? longitude,
  Object? mapsUrl,
  Object? locationAvailable,
}) {
  if (locationAvailable == true || locationAvailable?.toString() == 'true') {
    return true;
  }
  final lat = parseCapturedCoordinate(latitude);
  final lng = parseCapturedCoordinate(longitude);
  if (lat != null && lng != null) return true;
  final url = mapsUrl?.toString().trim() ?? '';
  return url.isNotEmpty;
}

String? capturedMapsUrl({
  Object? mapsUrl,
  Object? latitude,
  Object? longitude,
}) {
  final existing = mapsUrl?.toString().trim() ?? '';
  if (existing.isNotEmpty) return existing;
  final lat = parseCapturedCoordinate(latitude);
  final lng = parseCapturedCoordinate(longitude);
  if (lat == null || lng == null) return null;
  return 'https://www.google.com/maps?q=$lat,$lng';
}

/// Opens the location captured at activity time. Never uses live GPS.
Future<void> openCapturedMapsLocation(
  BuildContext context, {
  Object? mapsUrl,
  Object? latitude,
  Object? longitude,
  Object? locationAvailable,
}) async {
  final url = capturedMapsUrl(
    mapsUrl: mapsUrl,
    latitude: latitude,
    longitude: longitude,
  );

  if (url == null ||
      !hasCapturedCoordinates(
        latitude: latitude,
        longitude: longitude,
        mapsUrl: mapsUrl,
        locationAvailable: locationAvailable,
      )) {
    if (!context.mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Location Not Available')),
    );
    return;
  }

  final launched = await launchUrl(
    Uri.parse(url),
    mode: LaunchMode.externalApplication,
  );
  if (!launched && context.mounted) {
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Unable to open map.')),
    );
  }
}
