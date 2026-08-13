class Product {
  const Product({
    required this.id,
    required this.productCode,
    required this.productName,
    required this.dealerPrice,
    required this.gstPercentage,
    required this.nosPerCase,
    this.uom,
  });

  final int id;
  final String productCode;
  final String productName;
  final double dealerPrice;
  final double gstPercentage;
  final int nosPerCase;
  final String? uom;

  static const allowedGstValues = [0.0, 5.0, 12.0, 18.0, 28.0];

  double get orderGst => normalizeGst(gstPercentage);

  static bool isAllowedGst(double value) =>
      allowedGstValues.any((gst) => (gst - value).abs() < 0.001);

  static double normalizeGst(double value) {
    for (final gst in allowedGstValues) {
      if ((gst - value).abs() < 0.001) return gst;
    }
    return value;
  }

  factory Product.fromJson(Map<String, dynamic> json) => Product(
    id: int.tryParse('${json['id'] ?? ''}') ?? 0,
    productCode: json['product_code']?.toString() ?? '',
    productName: json['product_name']?.toString() ?? '',
    dealerPrice:
        double.tryParse('${json['dealer_price'] ?? json['rate'] ?? 0}') ?? 0,
    gstPercentage:
        double.tryParse(
          '${json['gst_percentage'] ?? json['gst_percent'] ?? 0}',
        ) ??
        0,
    nosPerCase: _parseNosPerCase(json['nos_per_case']),
    uom: json['uom']?.toString(),
  );

  static int _parseNosPerCase(Object? raw) {
    if (raw == null || '$raw'.trim().isEmpty) return 0;
    final value = int.tryParse('$raw') ?? 0;
    return value < 0 ? 0 : value;
  }

  bool matchesQuery(String query) {
    final value = query.trim().toLowerCase();
    if (value.isEmpty) return true;
    return productName.toLowerCase().contains(value) ||
        productCode.toLowerCase().contains(value);
  }
}
