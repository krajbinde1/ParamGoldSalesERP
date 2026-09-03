import 'product.dart';

enum OrderItemRateType {
  priceList,
  fixedRate;

  String get apiValue => switch (this) {
        OrderItemRateType.priceList => 'price_list',
        OrderItemRateType.fixedRate => 'fixed_rate',
      };

  String get label => switch (this) {
        OrderItemRateType.priceList => 'Price List',
        OrderItemRateType.fixedRate => 'Fixed Rate',
      };

  static OrderItemRateType fromApi(Object? value) {
    final raw = value?.toString().trim().toLowerCase();
    if (raw == 'fixed_rate' || raw == 'fixed') {
      return OrderItemRateType.fixedRate;
    }
    return OrderItemRateType.priceList;
  }

  /// Infer rate mode from a stored order line. A custom rate that does not
  /// match the price list is Fixed Rate even if rate_type is missing/wrong.
  static OrderItemRateType fromOrderJson(Map<String, dynamic> json) {
    final raw = json['rate_type']?.toString().trim().toLowerCase();
    if (raw == 'fixed_rate' || raw == 'fixed') {
      return OrderItemRateType.fixedRate;
    }
    final rate =
        double.tryParse('${json['rate_per_no'] ?? json['rate'] ?? 0}') ?? 0;
    final list = json['original_dealer_price'] == null
        ? null
        : double.tryParse('${json['original_dealer_price']}');
    if (list != null && (rate - list).abs() >= 0.001) {
      return OrderItemRateType.fixedRate;
    }
    return OrderItemRateType.priceList;
  }
}

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
    this.rateType = OrderItemRateType.priceList,
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
  OrderItemRateType rateType;

  /// Qty = Cases × Qty Per Case (nos_per_case).
  int get totalQuantityNos =>
      nosPerCase < 1 ? 0 : caseQuantity * nosPerCase;

  String get displaySummary => nosPerCase < 1
      ? 'Packing quantity missing'
      : '$caseQuantity Cases × $nosPerCase Nos = $totalQuantityNos Nos';

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
        rateType: OrderItemRateType.priceList,
      );

  factory OrderLineItem.fromOrderJson(Map<String, dynamic> item) {
    final rate = double.tryParse('${item['rate_per_no'] ?? item['rate'] ?? 0}') ?? 0;
    final listPrice = double.tryParse(
          '${item['original_dealer_price'] ?? item['rate_per_no'] ?? item['rate'] ?? 0}',
        ) ??
        0;
    final rateType = OrderItemRateType.fromOrderJson(item);

    return OrderLineItem(
      productId: int.tryParse('${item['product_id'] ?? 0}') ?? 0,
      productName: item['product_name']?.toString() ?? '-',
      productCode: item['product_code']?.toString() ?? '',
      caseQuantity: int.tryParse('${item['case_quantity'] ?? 1}') ?? 1,
      nosPerCase: int.tryParse('${item['nos_per_case'] ?? 1}') ?? 1,
      ratePerNo: rate,
      originalDealerPrice: listPrice,
      discountValue: rateType == OrderItemRateType.fixedRate
          ? 0
          : (double.tryParse('${item['discount_percentage'] ?? 0}') ?? 0),
      gstPercent: double.tryParse('${item['gst_percentage'] ?? 0}') ?? 0,
      rateType: rateType,
    );
  }

  bool get isPriceList => rateType == OrderItemRateType.priceList;

  bool get isFixedRate => rateType == OrderItemRateType.fixedRate;

  bool get isDiscountEnabled => isPriceList;

  double get baseAmount => totalQuantityNos * ratePerNo;

  double get discountAmount =>
      isDiscountEnabled ? baseAmount * discountValue / 100 : 0;

  /// Amount Without GST = Gross - Discount.
  double get taxableAmount => baseAmount - discountAmount;

  double get amountWithoutGst => taxableAmount;

  double get gstAmount => taxableAmount * gstPercent / 100;

  double get cgstAmount => gstAmount / 2;

  double get sgstAmount => gstAmount / 2;

  double get finalAmount => taxableAmount + gstAmount;

  bool get isValid => validationErrors.isEmpty;

  void setRateType(OrderItemRateType next) {
    if (rateType == next) return;
    rateType = next;
    if (next == OrderItemRateType.fixedRate) {
      discountValue = 0;
    } else {
      // Restore price-list rate; start discount at 0.
      ratePerNo = originalDealerPrice;
      discountValue = 0;
    }
  }

  void updateRatePerNo(double value) {
    if (!isFixedRate) return;
    ratePerNo = value < 0 ? 0 : value;
    discountValue = 0;
  }

  List<String> get validationErrors {
    final errors = <String>[];
    if (caseQuantity < 1) {
      errors.add('Cases must be at least 1.');
    }
    if (nosPerCase < 1) {
      errors.add(
        'Qty Per Case is missing for this product. Update packing in Product Master.',
      );
    }
    if (ratePerNo < 0) errors.add('Rate cannot be negative.');
    if (isFixedRate && ratePerNo <= 0) {
      errors.add('Enter a Fixed Rate greater than 0.');
    }
    if (isDiscountEnabled) {
      if (discountValue < 0 || discountValue > 100) {
        errors.add('Disc % must be between 0 and 100.');
      }
    } else if (discountValue != 0) {
      errors.add('Discount must be 0 for Fixed Rate.');
    }
    if (!Product.isAllowedGst(gstPercent)) {
      errors.add('GST must be one of the allowed values.');
    }
    return errors;
  }
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

  /// Gross before discount (sum of Qty × Rate). Kept for Manager/shared callers.
  final double subtotal;
  final double totalDiscount;
  final double totalGst;
  final double grandTotal;

  /// Sum of Amount Without GST (after discount, before GST).
  double get amountWithoutGstSubtotal => subtotal - totalDiscount;

  double get cgst => totalGst / 2;

  double get sgst => totalGst / 2;

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
