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
    this.cropName,
    this.district,
    this.farmerMobile,
  });

  final int id;
  final String farmerName;
  final String village;
  final String taluka;
  final DateTime activityDate;
  final String activityTime;
  final String status;
  final String? photoUrl;
  final String? cropName;
  final String? district;
  final String? farmerMobile;

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
        cropName: json['crop_name']?.toString(),
        district: json['district']?.toString(),
        farmerMobile: json['farmer_mobile']?.toString(),
      );

  static DateTime _parseDate(Object? value) {
    if (value == null) return DateTime.now();
    return DateFormat('yyyy-MM-dd').parse(value.toString());
  }
}
