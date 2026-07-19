import '../../models/attendance_format.dart';

class RoutePoint {
  const RoutePoint({
    required this.localUuid,
    required this.attendanceId,
    required this.latitude,
    required this.longitude,
    required this.recordedAt,
    this.accuracy,
    this.speed,
    required this.source,
    this.syncStatus = 'pending',
  });

  final String localUuid;
  final int attendanceId;
  final double latitude;
  final double longitude;
  final double? accuracy;
  final double? speed;
  final String recordedAt;
  final String source;
  final String syncStatus;

  Map<String, dynamic> toJson() => {
    'local_uuid': localUuid,
    'attendance_id': attendanceId,
    'latitude': latitude,
    'longitude': longitude,
    'accuracy': accuracy,
    'speed': speed,
    'recorded_at': recordedAt,
    'source': source,
    'sync_status': syncStatus,
  };

  factory RoutePoint.fromJson(Map<String, dynamic> json) => RoutePoint(
    localUuid: json['local_uuid']?.toString() ?? '',
    attendanceId: int.tryParse('${json['attendance_id'] ?? ''}') ?? 0,
    latitude: double.tryParse('${json['latitude'] ?? ''}') ?? 0,
    longitude: double.tryParse('${json['longitude'] ?? ''}') ?? 0,
    accuracy: json['accuracy'] == null
        ? null
        : double.tryParse('${json['accuracy']}'),
    speed: json['speed'] == null ? null : double.tryParse('${json['speed']}'),
    recordedAt: json['recorded_at']?.toString() ?? '',
    source: json['source']?.toString() ?? 'foreground',
    syncStatus: json['sync_status']?.toString() ?? 'pending',
  );

  Map<String, dynamic> toApiPayload() => {
    'local_uuid': localUuid,
    'latitude': latitude,
    'longitude': longitude,
    if (accuracy != null) 'accuracy': accuracy,
    if (speed != null) 'speed': speed,
    'recorded_at': recordedAt,
    'source': source,
  };

  static String formatRecordedAt(DateTime value) {
    final ist = AttendanceFormat.toIstWallClock(value.toUtc());
    String two(int n) => n.toString().padLeft(2, '0');

    return '${ist.year}-${two(ist.month)}-${two(ist.day)}T'
        '${two(ist.hour)}:${two(ist.minute)}:${two(ist.second)}+05:30';
  }
}

class RouteTrackingSession {
  const RouteTrackingSession({
    required this.attendanceId,
    required this.isActive,
    this.lastLatitude,
    this.lastLongitude,
    this.lastRecordedAt,
    this.statusMessage = 'Route tracking stopped',
  });

  final int attendanceId;
  final bool isActive;
  final double? lastLatitude;
  final double? lastLongitude;
  final String? lastRecordedAt;
  final String statusMessage;

  Map<String, dynamic> toJson() => {
    'attendance_id': attendanceId,
    'is_active': isActive,
    'last_latitude': lastLatitude,
    'last_longitude': lastLongitude,
    'last_recorded_at': lastRecordedAt,
    'status_message': statusMessage,
  };

  factory RouteTrackingSession.fromJson(Map<String, dynamic> json) =>
      RouteTrackingSession(
        attendanceId: int.tryParse('${json['attendance_id'] ?? ''}') ?? 0,
        isActive: json['is_active'] == true,
        lastLatitude: json['last_latitude'] == null
            ? null
            : double.tryParse('${json['last_latitude']}'),
        lastLongitude: json['last_longitude'] == null
            ? null
            : double.tryParse('${json['last_longitude']}'),
        lastRecordedAt: json['last_recorded_at']?.toString(),
        statusMessage:
            json['status_message']?.toString() ?? 'Route tracking stopped',
      );

  RouteTrackingSession copyWith({
    int? attendanceId,
    bool? isActive,
    double? lastLatitude,
    double? lastLongitude,
    String? lastRecordedAt,
    String? statusMessage,
  }) => RouteTrackingSession(
    attendanceId: attendanceId ?? this.attendanceId,
    isActive: isActive ?? this.isActive,
    lastLatitude: lastLatitude ?? this.lastLatitude,
    lastLongitude: lastLongitude ?? this.lastLongitude,
    lastRecordedAt: lastRecordedAt ?? this.lastRecordedAt,
    statusMessage: statusMessage ?? this.statusMessage,
  );
}
