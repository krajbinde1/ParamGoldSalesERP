import '../../orders/models/order_dealer.dart';

class CollectionDetail {
  const CollectionDetail({
    required this.id,
    required this.receiptNo,
    required this.dealerName,
    required this.amount,
    required this.collectionDate,
    required this.status,
    required this.statusLabel,
    this.dealer,
    this.photoUrl,
    this.employeeRemarks,
    this.adminRemark,
  });

  final int id;
  final String receiptNo;
  final String dealerName;
  final double amount;
  final DateTime collectionDate;
  final String status;
  final String statusLabel;
  final OrderDealer? dealer;
  final String? photoUrl;
  final String? employeeRemarks;
  final String? adminRemark;

  factory CollectionDetail.fromJson(Map<String, dynamic> json) {
    final dealerJson = json['dealer'];
    return CollectionDetail(
      id: int.tryParse('${json['id'] ?? ''}') ?? 0,
      receiptNo: json['receipt_no']?.toString() ?? '',
      dealerName: json['dealer_name']?.toString() ?? '-',
      amount: _asDouble(json['amount']) ?? 0,
      collectionDate:
          DateTime.tryParse(json['collection_date']?.toString() ?? '') ??
          DateTime.now(),
      status: json['status']?.toString() ?? 'pending',
      statusLabel: json['status_label']?.toString() ?? 'Pending Verification',
      dealer: dealerJson is Map
          ? OrderDealer.fromJson(Map<String, dynamic>.from(dealerJson))
          : null,
      photoUrl: json['photo_url']?.toString(),
      employeeRemarks: json['employee_remarks']?.toString(),
      adminRemark: json['admin_remark']?.toString(),
    );
  }

  static double? _asDouble(Object? value) {
    if (value is num) return value.toDouble();
    return double.tryParse('$value');
  }
}
