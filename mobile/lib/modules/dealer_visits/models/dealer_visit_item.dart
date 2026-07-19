import 'package:intl/intl.dart';

class DealerVisitItem {
  const DealerVisitItem({
    required this.id,
    required this.dealerName,
    required this.visitDate,
    required this.visitTime,
    required this.status,
    this.photoUrl,
    this.latitude,
    this.longitude,
  });

  final int id;
  final String dealerName;
  final DateTime visitDate;
  final String visitTime;
  final String status;
  final String? photoUrl;
  final double? latitude;
  final double? longitude;

  factory DealerVisitItem.fromJson(Map<String, dynamic> json) =>
      DealerVisitItem(
        id: int.tryParse('${json['id'] ?? ''}') ?? 0,
        dealerName: json['dealer_name']?.toString() ?? '-',
        visitDate: DateFormat(
          'yyyy-MM-dd',
        ).parse(json['visit_date']?.toString() ?? DateTime.now().toString()),
        visitTime: json['visit_time']?.toString() ?? '-',
        status: json['status']?.toString() ?? 'completed',
        photoUrl: json['photo_url']?.toString(),
        latitude: double.tryParse('${json['latitude'] ?? ''}'),
        longitude: double.tryParse('${json['longitude'] ?? ''}'),
      );
}
