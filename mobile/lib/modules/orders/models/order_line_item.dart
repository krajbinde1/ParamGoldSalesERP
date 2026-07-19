import 'product.dart';

class OrderLineItem {
  OrderLineItem({
    required this.productId,
    required this.productName,
    required this.productCode,
    required this.caseQuantity,
    required this.nosPerCase,
    required this.ratePerNo,
    required this.originalDealerPrice,
    required this.discountValue,
    required this.gstPercent,
  });

  final int productId;
  final String productName;
  final String productCode;
  final int nosPerCase;
  final double originalDealerPrice;
  int caseQuantity;
  double ratePerNo;
  double discountValue;
  final double gstPercent;

  int get totalQuantityNos => caseQuantity * nosPerCase;

  String get displaySummary =>
      '$caseQuantity Cases × $nosPerCase Nos = $totalQuantityNos Nos';

  factory OrderLineItem.fromProduct(Product product) => OrderLineItem(
    productId: product.id,
    productName: product.productName,
    productCode: product.productCode,
    caseQuantity: 1,
    nosPerCase: product.nosPerCase,
    ratePerNo: product.dealerPrice,
    originalDealerPrice: product.dealerPrice,
    discountValue: 0,
    gstPercent: product.orderGst,
  );

  bool get isDiscountEnabled => _sameAmount(ratePerNo, originalDealerPrice);

  double get baseAmount => totalQuantityNos * ratePerNo;

  double get discountAmount =>
      isDiscountEnabled ? baseAmount * discountValue / 100 : 0;

  double get taxableAmount => baseAmount - discountAmount;

  double get gstAmount => taxableAmount * gstPercent / 100;

  double get finalAmount => taxableAmount + gstAmount;

  bool get isValid => validationErrors.isEmpty;

  void updateRatePerNo(double value) {
    ratePerNo = value;
    if (!isDiscountEnabled) {
      discountValue = 0;
    }
  }

  List<String> get validationErrors {
    final errors = <String>[];
    if (caseQuantity < 1) {
      errors.add('Case quantity must be at least 1.');
    }
    if (ratePerNo < 0) errors.add('Rate per No cannot be negative.');
    if (isDiscountEnabled) {
      if (discountValue < 0 || discountValue > 100) {
        errors.add('Percentage discount must be between 0 and 100.');
      }
    } else if (discountValue != 0) {
      errors.add('Discount must be 0 when rate is changed.');
    }
    if (!Product.isAllowedGst(gstPercent)) {
      errors.add('GST must be one of the allowed values.');
    }
    return errors;
  }

  bool _sameAmount(double a, double b) => (a - b).abs() < 0.001;
}

class OrderSummaryTotals {
  const OrderSummaryTotals({
    required this.totalProducts,
    required this.totalCases,
    required this.totalQuantityNos,
    required this.subtotal,
    required this.totalDiscount,
    required this.totalGst,
    required this.grandTotal,
  });

  final int totalProducts;
  final int totalCases;
  final int totalQuantityNos;
  final double subtotal;
  final double totalDiscount;
  final double totalGst;
  final double grandTotal;

  static OrderSummaryTotals fromItems(List<OrderLineItem> items) {
    final validItems = items.where((item) => item.isValid).toList();
    var subtotal = 0.0;
    var totalDiscount = 0.0;
    var totalGst = 0.0;
    var totalCases = 0;
    var totalQuantityNos = 0;

    for (final item in validItems) {
      subtotal += item.baseAmount;
      totalDiscount += item.discountAmount;
      totalGst += item.gstAmount;
      totalCases += item.caseQuantity;
      totalQuantityNos += item.totalQuantityNos;
    }

    return OrderSummaryTotals(
      totalProducts: validItems.length,
      totalCases: totalCases,
      totalQuantityNos: totalQuantityNos,
      subtotal: subtotal,
      totalDiscount: totalDiscount,
      totalGst: totalGst,
      grandTotal: subtotal - totalDiscount + totalGst,
    );
  }
}
