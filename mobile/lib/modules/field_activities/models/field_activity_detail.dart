import 'package:intl/intl.dart';

class FieldActivityRecommendationItem {
  const FieldActivityRecommendationItem({
    required this.productName,
    this.dosage,
    this.remark,
  });

  final String productName;
  final String? dosage;
  final String? remark;

  factory FieldActivityRecommendationItem.fromJson(Map<String, dynamic> json) =>
      FieldActivityRecommendationItem(
        productName: json['product_name']?.toString() ?? '-',
        dosage: json['dosage']?.toString(),
        remark: json['remark']?.toString(),
      );
}

class FieldActivityDetail {
  const FieldActivityDetail({
    required this.id,
    required this.farmerName,
    required this.village,
    required this.taluka,
    required this.activityDate,
    required this.activityTime,
    this.farmerMobile,
    this.district,
    this.cropName,
    this.remark,
    this.photoUrl,
    this.latitude,
    this.longitude,
    this.mapsUrl,
    this.employeeName,
    this.employeeCode,
    required this.status,
    required this.statusLabel,
    this.recommendations = const [],
  });

  final int id;
  final String farmerName;
  final String village;
  final String taluka;
  final DateTime activityDate;
  final String activityTime;
  final String? farmerMobile;
  final String? district;
  final String? cropName;
  final String? remark;
  final String? photoUrl;
  final double? latitude;
  final double? longitude;
  final String? mapsUrl;
  final String? employeeName;
  final String? employeeCode;
  final String status;
  final String statusLabel;
  final List<FieldActivityRecommendationItem> recommendations;

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
        farmerMobile: json['farmer_mobile']?.toString(),
        district: json['district']?.toString(),
        cropName: json['crop_name']?.toString(),
        remark: json['remark']?.toString(),
        photoUrl: json['photo_url']?.toString(),
        latitude: double.tryParse('${json['latitude'] ?? ''}'),
        longitude: double.tryParse('${json['longitude'] ?? ''}'),
        mapsUrl: json['maps_url']?.toString(),
        employeeName: json['employee_name']?.toString(),
        employeeCode: json['employee_code']?.toString(),
        status: json['status']?.toString() ?? 'completed',
        statusLabel: json['status_label']?.toString() ?? 'Completed',
        recommendations: (json['recommendations'] as List?)
                ?.map(
                  (item) => FieldActivityRecommendationItem.fromJson(
                    Map<String, dynamic>.from(item as Map),
                  ),
                )
                .toList() ??
            const [],
      );
}
