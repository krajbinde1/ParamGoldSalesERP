class CollectionSummary {
  const CollectionSummary({
    required this.totalCollection,
    required this.monthCollection,
    required this.weekCollection,
    required this.totalEntries,
  });

  final double totalCollection;
  final double monthCollection;
  final double weekCollection;
  final int totalEntries;

  factory CollectionSummary.fromJson(Map<String, dynamic> json) =>
      CollectionSummary(
        totalCollection: _asDouble(json['total_collection']) ?? 0,
        monthCollection: _asDouble(json['month_collection']) ?? 0,
        weekCollection: _asDouble(json['week_collection']) ?? 0,
        totalEntries: _asInt(json['total_entries']) ?? 0,
      );

  static const empty = CollectionSummary(
    totalCollection: 0,
    monthCollection: 0,
    weekCollection: 0,
    totalEntries: 0,
  );

  static double? _asDouble(Object? value) {
    if (value is num) return value.toDouble();
    return double.tryParse('$value');
  }

  static int? _asInt(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse('$value');
  }
}
