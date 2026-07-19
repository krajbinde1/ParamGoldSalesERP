import 'package:intl/intl.dart';

class CollectionItem {
  const CollectionItem({
    required this.id,
    required this.dealerName,
    required this.amount,
    required this.collectionDate,
    required this.status,
    this.photoUrl,
  });

  final int id;
  final String dealerName;
  final double amount;
  final DateTime collectionDate;
  final String status;
  final String? photoUrl;

  factory CollectionItem.fromJson(Map<String, dynamic> json) => CollectionItem(
    id: int.tryParse('${json['id'] ?? ''}') ?? 0,
    dealerName: json['dealer_name']?.toString() ?? '-',
    amount: _asDouble(json['amount']) ?? 0,
    collectionDate: _parseDate(json['collection_date']),
    status: json['status']?.toString() ?? 'pending',
    photoUrl: json['photo_url']?.toString(),
  );

  static double? _asDouble(Object? value) {
    if (value is num) return value.toDouble();
    return double.tryParse('$value');
  }

  static DateTime _parseDate(Object? value) {
    if (value == null) return DateTime.now();
    return DateFormat('yyyy-MM-dd').parse(value.toString());
  }
}
