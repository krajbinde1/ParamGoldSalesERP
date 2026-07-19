import 'package:intl/intl.dart';

class TaDaClaimItem {
  const TaDaClaimItem({
    required this.id,
    required this.claimDate,
    required this.fromLocation,
    required this.toLocation,
    required this.route,
    required this.travelKm,
    required this.totalAmount,
    required this.status,
    required this.statusLabel,
    this.billPhotoUrl,
  });

  final int id;
  final DateTime claimDate;
  final String fromLocation;
  final String toLocation;
  final String route;
  final double travelKm;
  final double totalAmount;
  final String status;
  final String statusLabel;
  final String? billPhotoUrl;

  factory TaDaClaimItem.fromJson(Map<String, dynamic> json) => TaDaClaimItem(
    id: int.tryParse('${json['id'] ?? ''}') ?? 0,
    claimDate: DateFormat(
      'yyyy-MM-dd',
    ).parse(json['claim_date']?.toString() ?? DateTime.now().toString()),
    fromLocation: json['from_location']?.toString() ?? '-',
    toLocation: json['to_location']?.toString() ?? '-',
    route: json['route']?.toString() ?? '-',
    travelKm: double.tryParse('${json['travel_km'] ?? ''}') ?? 0,
    totalAmount: double.tryParse('${json['total_amount'] ?? ''}') ?? 0,
    status: json['status']?.toString() ?? 'pending',
    statusLabel: json['status_label']?.toString() ?? 'Pending',
    billPhotoUrl: json['bill_photo_url']?.toString(),
  );
}
