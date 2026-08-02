import '../../models/attendance_format.dart';

class RoutePoint {
  const RoutePoint({
    required this.localUuid,
    required this.attendanceId,
    required this.latitude,
    required this.longitude,
    required this.recordedAt,
    this.employeeId,
    this.accuracy,
    this.speed,
    this.heading,
    required this.source,
    this.syncStatus = 'pending',
  });

  final String localUuid;
  final int attendanceId;
  final int? employeeId;
  final double latitude;
  final double longitude;
  final double? accuracy;
  final double? speed;
  final double? heading;
  final String recordedAt;
  final String source;
  final String syncStatus;

  Map<String, dynamic> toJson() => {
    'local_uuid': localUuid,
    'attendance_id': attendanceId,
    'employee_id': employeeId,
    'latitude': latitude,
    'longitude': longitude,
    'accuracy': accuracy,
    'speed': speed,
    'heading': heading,
    'recorded_at': recordedAt,
    'source': source,
    'sync_status': syncStatus,
  };

  factory RoutePoint.fromJson(Map<String, dynamic> json) => RoutePoint(
    localUuid: json['local_uuid']?.toString() ?? '',
    attendanceId: int.tryParse('${json['attendance_id'] ?? ''}') ?? 0,
    employeeId: json['employee_id'] == null
        ? null
        : int.tryParse('${json['employee_id']}'),
    latitude: double.tryParse('${json['latitude'] ?? ''}') ?? 0,
    longitude: double.tryParse('${json['longitude'] ?? ''}') ?? 0,
    accuracy: json['accuracy'] == null
        ? null
        : double.tryParse('${json['accuracy']}'),
    speed: json['speed'] == null ? null : double.tryParse('${json['speed']}'),
    heading: json['heading'] == null
        ? null
        : double.tryParse('${json['heading']}'),
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
    if (heading != null) 'heading': heading,
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
    this.employeeId,
    this.lastLatitude,
    this.lastLongitude,
    this.lastRecordedAt,
    this.statusMessage = 'Route tracking stopped',
    this.gpsStatus = 'unknown',
    this.permissionStatus = 'unknown',
  });

  final int attendanceId;
  final int? employeeId;
  final bool isActive;
  final double? lastLatitude;
  final double? lastLongitude;
  final String? lastRecordedAt;
  final String statusMessage;
  final String gpsStatus;
  final String permissionStatus;

  Map<String, dynamic> toJson() => {
    'attendance_id': attendanceId,
    'employee_id': employeeId,
    'is_active': isActive,
    'last_latitude': lastLatitude,
    'last_longitude': lastLongitude,
    'last_recorded_at': lastRecordedAt,
    'status_message': statusMessage,
    'gps_status': gpsStatus,
    'permission_status': permissionStatus,
  };

  factory RouteTrackingSession.fromJson(Map<String, dynamic> json) =>
      RouteTrackingSession(
        attendanceId: int.tryParse('${json['attendance_id'] ?? ''}') ?? 0,
        employeeId: json['employee_id'] == null
            ? null
            : int.tryParse('${json['employee_id']}'),
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
        gpsStatus: json['gps_status']?.toString() ?? 'unknown',
        permissionStatus: json['permission_status']?.toString() ?? 'unknown',
      );

  RouteTrackingSession copyWith({
    int? attendanceId,
    int? employeeId,
    bool? isActive,
    double? lastLatitude,
    double? lastLongitude,
    String? lastRecordedAt,
    String? statusMessage,
    String? gpsStatus,
    String? permissionStatus,
  }) => RouteTrackingSession(
    attendanceId: attendanceId ?? this.attendanceId,
    employeeId: employeeId ?? this.employeeId,
    isActive: isActive ?? this.isActive,
    lastLatitude: lastLatitude ?? this.lastLatitude,
    lastLongitude: lastLongitude ?? this.lastLongitude,
    lastRecordedAt: lastRecordedAt ?? this.lastRecordedAt,
    statusMessage: statusMessage ?? this.statusMessage,
    gpsStatus: gpsStatus ?? this.gpsStatus,
    permissionStatus: permissionStatus ?? this.permissionStatus,
  );
}

/// Snapshot for attendance screen status row.
class RouteTrackingUiStatus {
  const RouteTrackingUiStatus({
    required this.message,
    required this.isActive,
    this.lastLocationAt,
    this.pendingSyncCount = 0,
    this.gpsStatus = 'unknown',
    this.permissionStatus = 'unknown',
  });

  final String message;
  final bool isActive;
  final String? lastLocationAt;
  final int pendingSyncCount;
  final String gpsStatus;
  final String permissionStatus;

  static const empty = RouteTrackingUiStatus(
    message: '',
    isActive: false,
  );
}
