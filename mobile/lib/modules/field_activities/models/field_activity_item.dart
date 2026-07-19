import 'package:intl/intl.dart';

class FieldActivityItem {
  const FieldActivityItem({
    required this.id,
    required this.farmerName,
    required this.village,
    required this.taluka,
    required this.activityDate,
    required this.activityTime,
    required this.status,
    this.photoUrl,
  });

  final int id;
  final String farmerName;
  final String village;
  final String taluka;
  final DateTime activityDate;
  final String activityTime;
  final String status;
  final String? photoUrl;

  factory FieldActivityItem.fromJson(Map<String, dynamic> json) =>
      FieldActivityItem(
        id: int.tryParse('${json['id'] ?? ''}') ?? 0,
        farmerName: json['farmer_name']?.toString() ?? '-',
        village: json['village']?.toString() ?? '-',
        taluka: json['taluka']?.toString() ?? '-',
        activityDate: _parseDate(json['activity_date']),
        activityTime: json['activity_time']?.toString() ?? '-',
        status: json['status']?.toString() ?? 'completed',
        photoUrl: json['photo_url']?.toString(),
      );

  static DateTime _parseDate(Object? value) {
    if (value == null) return DateTime.now();
    return DateFormat('yyyy-MM-dd').parse(value.toString());
  }
}
