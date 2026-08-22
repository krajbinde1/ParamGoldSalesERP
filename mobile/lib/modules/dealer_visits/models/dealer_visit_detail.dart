import 'package:intl/intl.dart';

class DealerVisitDetail {
  const DealerVisitDetail({
    required this.id,
    this.dealerId,
    required this.dealerName,
    this.ownerName,
    this.village,
    required this.visitDate,
    required this.visitTime,
    this.photoUrl,
    required this.latitude,
    required this.longitude,
    required this.accuracy,
    this.locationCapturedAt,
    this.mapsUrl,
    this.employeeName,
    required this.status,
    required this.statusLabel,
  });

  final int id;
  final int? dealerId;
  final String dealerName;
  final String? ownerName;
  final String? village;
  final DateTime visitDate;
  final String visitTime;
  final String? photoUrl;
  final double latitude;
  final double longitude;
  final double accuracy;
  final DateTime? locationCapturedAt;
  final String? mapsUrl;
  final String? employeeName;
  final String status;
  final String statusLabel;

  factory DealerVisitDetail.fromJson(Map<String, dynamic> json) =>
      DealerVisitDetail(
        id: int.tryParse('${json['id'] ?? ''}') ?? 0,
        dealerId: int.tryParse('${json['dealer_id'] ?? ''}'),
        dealerName: json['dealer_name']?.toString() ?? '-',
        ownerName: json['owner_name']?.toString(),
        village: json['village']?.toString(),
        visitDate: DateFormat(
          'yyyy-MM-dd',
        ).parse(json['visit_date']?.toString() ?? DateTime.now().toString()),
        visitTime: json['visit_time']?.toString() ?? '-',
        photoUrl: json['photo_url']?.toString(),
        latitude: double.tryParse('${json['latitude'] ?? ''}') ?? 0,
        longitude: double.tryParse('${json['longitude'] ?? ''}') ?? 0,
        accuracy: double.tryParse('${json['accuracy'] ?? ''}') ?? 0,
        locationCapturedAt: json['location_captured_at'] == null
            ? null
            : DateTime.tryParse(json['location_captured_at'].toString()),
        mapsUrl: json['maps_url']?.toString(),
        employeeName: json['employee_name']?.toString(),
        status: json['status']?.toString() ?? 'completed',
        statusLabel: json['status_label']?.toString() ?? 'Completed',
      );
}
