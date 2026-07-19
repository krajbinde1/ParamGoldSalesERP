class PunchCapture {
  const PunchCapture({
    required this.latitude,
    required this.longitude,
    required this.address,
    required this.photoPath,
    required this.capturedAt,
  });
  final double latitude, longitude;
  final String address, photoPath;
  final DateTime capturedAt;
  Map<String, dynamic> toJson() => {
    'latitude': latitude,
    'longitude': longitude,
    'location_address': address,
    'photo_path': photoPath,
    'captured_at': capturedAt.toIso8601String(),
  };
  factory PunchCapture.fromJson(Map<String, dynamic> j) => PunchCapture(
    latitude: (j['latitude'] as num).toDouble(),
    longitude: (j['longitude'] as num).toDouble(),
    address: j['location_address'] as String,
    photoPath: j['photo_path'] as String,
    capturedAt: DateTime.parse(j['captured_at'] as String),
  );
}
