import 'order.dart';
import 'order_dealer.dart';
import 'order_line_item.dart';

class OrderDetailItem {
  const OrderDetailItem({
    required this.productId,
    required this.productName,
    required this.productCode,
    required this.caseQuantity,
    required this.nosPerCase,
    required this.totalQuantityNos,
    required this.ratePerNo,
    required this.discountPercentage,
    required this.gstPercentage,
    required this.lineTotal,
    this.originalDealerPrice,
    this.baseAmount,
    this.discountAmount,
    this.taxableAmount,
    this.gstAmount,
    this.finalAmount,
    this.displaySummary,
  });

  final int productId;
  final String productName;
  final String productCode;
  final int caseQuantity;
  final int nosPerCase;
  final int totalQuantityNos;
  final double? originalDealerPrice;
  final double ratePerNo;
  final double discountPercentage;
  final double gstPercentage;
  final double lineTotal;
  final double? baseAmount;
  final double? discountAmount;
  final double? taxableAmount;
  final double? gstAmount;
  final double? finalAmount;
  final String? displaySummary;

  String get quantitySummary =>
      displaySummary ??
      '$caseQuantity Cases × $nosPerCase Nos = $totalQuantityNos Nos';

  factory OrderDetailItem.fromJson(Map<String, dynamic> json) =>
      OrderDetailItem(
        productId: int.tryParse('${json['product_id'] ?? ''}') ?? 0,
        productName: json['product_name']?.toString() ?? '—',
        productCode: json['product_code']?.toString() ?? '',
        caseQuantity: int.tryParse('${json['case_quantity'] ?? 1}') ?? 1,
        nosPerCase: int.tryParse('${json['nos_per_case'] ?? 1}') ?? 1,
        totalQuantityNos:
            int.tryParse('${json['total_quantity_nos'] ?? 0}') ?? 0,
        originalDealerPrice: json['original_dealer_price'] == null
            ? null
            : double.tryParse('${json['original_dealer_price']}'),
        ratePerNo: double.tryParse(
              '${json['rate_per_no'] ?? json['rate'] ?? 0}',
            ) ??
            0,
        discountPercentage:
            double.tryParse('${json['discount_percentage'] ?? 0}') ?? 0,
        gstPercentage: double.tryParse('${json['gst_percentage'] ?? 0}') ?? 0,
        baseAmount: json['base_amount'] == null
            ? null
            : double.tryParse('${json['base_amount']}'),
        discountAmount: json['discount_amount'] == null
            ? null
            : double.tryParse('${json['discount_amount']}'),
        taxableAmount: json['taxable_amount'] == null
            ? null
            : double.tryParse('${json['taxable_amount']}'),
        gstAmount: json['gst_amount'] == null
            ? null
            : double.tryParse('${json['gst_amount']}'),
        finalAmount: json['final_amount'] == null
            ? null
            : double.tryParse('${json['final_amount']}'),
        lineTotal: double.tryParse(
              '${json['line_total'] ?? json['final_amount'] ?? 0}',
            ) ??
            0,
        displaySummary: json['display_summary']?.toString(),
      );
}

class OrderDetail {
  const OrderDetail({
    required this.id,
    required this.orderNo,
    required this.orderDate,
    required this.status,
    required this.remarks,
    required this.dealerName,
    required this.salesEmployeeName,
    required this.subtotal,
    required this.discountAmount,
    required this.gstAmount,
    required this.grandTotal,
    required this.canEdit,
    required this.items,
    this.totalCases,
    this.totalQuantityNos,
    this.dealer,
  });

  final int id;
  final String orderNo;
  final DateTime orderDate;
  final String status;
  final String remarks;
  final String dealerName;
  final String salesEmployeeName;
  final double subtotal;
  final double discountAmount;
  final double gstAmount;
  final double grandTotal;
  final int? totalCases;
  final int? totalQuantityNos;
  final bool canEdit;
  final List<OrderDetailItem> items;
  final OrderDealer? dealer;

  factory OrderDetail.fromJson(Map<String, dynamic> json) {
    final dateRaw = json['order_date']?.toString() ?? '';
    final dealerJson = json['dealer'];
    final rawItems = json['items'];

    return OrderDetail(
      id: int.tryParse('${json['id'] ?? ''}') ?? 0,
      orderNo: json['order_no']?.toString() ?? '—',
      orderDate: DateTime.tryParse(dateRaw) ?? DateTime.now(),
      status: json['status']?.toString() ?? 'pending',
      remarks: json['remarks']?.toString() ?? '',
      dealerName:
          json['dealer_name']?.toString() ??
          (dealerJson is Map ? dealerJson['firm_name']?.toString() : null) ??
          '—',
      salesEmployeeName: json['sales_employee_name']?.toString() ?? '—',
      subtotal: double.tryParse('${json['subtotal'] ?? 0}') ?? 0,
      discountAmount: double.tryParse('${json['discount_amount'] ?? 0}') ?? 0,
      gstAmount: double.tryParse('${json['gst_amount'] ?? 0}') ?? 0,
      grandTotal: double.tryParse('${json['grand_total'] ?? 0}') ?? 0,
      totalCases: json['total_cases'] == null
          ? null
          : int.tryParse('${json['total_cases']}'),
      totalQuantityNos: json['total_quantity_nos'] == null
          ? null
          : int.tryParse('${json['total_quantity_nos']}'),
      canEdit: json['can_edit'] == true,
      dealer: dealerJson is Map
          ? OrderDealer.fromJson(Map<String, dynamic>.from(dealerJson))
          : null,
      items: rawItems is List
          ? rawItems
                .map(
                  (item) => OrderDetailItem.fromJson(
                    Map<String, dynamic>.from(item as Map),
                  ),
                )
                .toList()
          : const [],
    );
  }

  List<OrderTimelineStep> get timeline => OrderTimelineStep.build(status);
}

class OrderTimelineStep {
  const OrderTimelineStep({
    required this.label,
    required this.isComplete,
    required this.isCurrent,
    required this.isRejected,
  });

  final String label;
  final bool isComplete;
  final bool isCurrent;
  final bool isRejected;

  static List<OrderTimelineStep> build(String status) {
    final value = OrderStatusRules.normalize(status);

    if (OrderStatusRules.isRejectedBucket(value)) {
      final label = value == 'cancelled' ? 'Cancelled' : 'Rejected';
      return [
        const OrderTimelineStep(
          label: 'Pending Approval',
          isComplete: true,
          isCurrent: false,
          isRejected: false,
        ),
        OrderTimelineStep(
          label: label,
          isComplete: true,
          isCurrent: true,
          isRejected: true,
        ),
      ];
    }

    final steps = <OrderTimelineStep>[
      OrderTimelineStep(
        label: 'Pending Approval',
        isComplete:
            value != 'pending_approval' &&
            value != 'pending' &&
            value != 'draft',
        isCurrent: {
          'pending_approval',
          'pending',
          'draft',
          'processing',
        }.contains(value),
        isRejected: false,
      ),
      OrderTimelineStep(
        label: 'Approved',
        isComplete: {'dispatched', 'delivered'}.contains(value),
        isCurrent: value == 'approved',
        isRejected: false,
      ),
      OrderTimelineStep(
        label: 'Dispatched',
        isComplete: value == 'delivered',
        isCurrent: value == 'dispatched',
        isRejected: false,
      ),
    ];

    return steps;
  }
}

extension OrderDetailDraft on OrderDetail {
  List<OrderLineItem> toLineItems() => items
      .map(
        (item) => OrderLineItem(
          productId: item.productId,
          productName: item.productName,
          productCode: item.productCode,
          caseQuantity: item.caseQuantity,
          nosPerCase: item.nosPerCase,
          ratePerNo: item.ratePerNo,
          originalDealerPrice: item.originalDealerPrice ?? item.ratePerNo,
          discountValue: item.discountPercentage,
          gstPercent: item.gstPercentage,
        ),
      )
      .toList();
}
