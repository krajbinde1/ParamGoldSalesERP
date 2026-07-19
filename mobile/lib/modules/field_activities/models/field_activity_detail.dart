import 'package:intl/intl.dart';

class FieldActivityDetail {
  const FieldActivityDetail({
    required this.id,
    required this.farmerName,
    required this.village,
    required this.taluka,
    required this.activityDate,
    required this.activityTime,
    this.photoUrl,
    this.latitude,
    this.longitude,
    this.employeeName,
    required this.status,
    required this.statusLabel,
  });

  final int id;
  final String farmerName;
  final String village;
  final String taluka;
  final DateTime activityDate;
  final String activityTime;
  final String? photoUrl;
  final double? latitude;
  final double? longitude;
  final String? employeeName;
  final String status;
  final String statusLabel;

  factory FieldActivityDetail.fromJson(Map<String, dynamic> json) =>
      FieldActivityDetail(
        id: int.tryParse('${json['id'] ?? ''}') ?? 0,
        farmerName: json['farmer_name']?.toString() ?? '-',
        village: json['village']?.toString() ?? '-',
        taluka: json['taluka']?.toString() ?? '-',
        activityDate: DateFormat(
          'yyyy-MM-dd',
        ).parse(json['activity_date']?.toString() ?? DateTime.now().toString()),
        activityTime: json['activity_time']?.toString() ?? '-',
        photoUrl: json['photo_url']?.toString(),
        latitude: double.tryParse('${json['latitude'] ?? ''}'),
        longitude: double.tryParse('${json['longitude'] ?? ''}'),
        employeeName: json['employee_name']?.toString(),
        status: json['status']?.toString() ?? 'completed',
        statusLabel: json['status_label']?.toString() ?? 'Completed',
      );
}
