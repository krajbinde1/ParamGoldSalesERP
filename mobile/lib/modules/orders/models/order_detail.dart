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
    this.unit,
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
  final String? unit;
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
        unit: json['unit']?.toString(),
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
    this.statusLabel,
    this.totalCases,
    this.totalQuantityNos,
    this.dealer,
    this.approvedAt,
    this.approvedAtLabel,
    this.approvedByName,
    this.approvedByRole,
    this.approvalSummary,
    this.rejectedAt,
    this.rejectedByName,
    this.rejectedByRole,
    this.rejectionRemark,
    this.sentForBillAt,
    this.sentForBillAtLabel,
    this.sentForBillByName,
    this.billedAt,
    this.billedAtLabel,
    this.billedByName,
    this.billUrl,
    this.dispatchedAt,
    this.dispatchedByName,
    this.transportAmount,
    this.timelineSteps = const [],
  });

  final int id;
  final String orderNo;
  final DateTime orderDate;
  final String status;
  final String? statusLabel;
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
  final String? approvedAt;
  final String? approvedAtLabel;
  final String? approvedByName;
  final String? approvedByRole;
  final String? approvalSummary;
  final String? rejectedAt;
  final String? rejectedByName;
  final String? rejectedByRole;
  final String? rejectionRemark;
  final String? sentForBillAt;
  final String? sentForBillAtLabel;
  final String? sentForBillByName;
  final String? billedAt;
  final String? billedAtLabel;
  final String? billedByName;
  final String? billUrl;
  final String? dispatchedAt;
  final String? dispatchedByName;
  final double? transportAmount;
  final List<OrderTimelineStep> timelineSteps;

  /// Display-only short form, e.g. `PG-20260813-0004` → `PG-0004`.
  String get displayOrderNo => OrderNoDisplay.short(orderNo);

  factory OrderDetail.fromJson(Map<String, dynamic> json) {
    final dateRaw = json['order_date']?.toString() ?? '';
    final dealerJson = json['dealer'];
    final rawItems = json['items'];
    final rawTimeline = json['timeline'];

    return OrderDetail(
      id: int.tryParse('${json['id'] ?? ''}') ?? 0,
      orderNo: json['order_no']?.toString() ?? '—',
      orderDate: DateTime.tryParse(dateRaw) ?? DateTime.now(),
      status: json['status']?.toString() ?? 'pending',
      statusLabel: json['status_label']?.toString(),
      remarks: json['remarks']?.toString() ?? '',
      dealerName:
          json['dealer_name']?.toString() ??
          (dealerJson is Map ? dealerJson['firm_name']?.toString() : null) ??
          '—',
      salesEmployeeName: json['sales_employee_name']?.toString() ??
          json['employee_name']?.toString() ??
          '—',
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
      approvedAt: json['approved_at']?.toString(),
      approvedAtLabel: json['approved_at_label']?.toString(),
      approvedByName: json['approved_by_name']?.toString(),
      approvedByRole: json['approved_by_role']?.toString(),
      approvalSummary: json['approval_summary']?.toString(),
      rejectedAt: json['rejected_at']?.toString(),
      rejectedByName: json['rejected_by_name']?.toString(),
      rejectedByRole: json['rejected_by_role']?.toString(),
      rejectionRemark:
          json['rejection_remark']?.toString() ??
          json['rejection_reason']?.toString(),
      sentForBillAt: json['sent_for_bill_at']?.toString(),
      sentForBillAtLabel: json['sent_for_bill_at_label']?.toString(),
      sentForBillByName: json['sent_for_bill_by_name']?.toString(),
      billedAt: json['billed_at']?.toString(),
      billedAtLabel: json['billed_at_label']?.toString(),
      billedByName: json['billed_by_name']?.toString(),
      billUrl: json['bill_url']?.toString(),
      dispatchedAt: json['dispatched_at']?.toString(),
      dispatchedByName: json['dispatched_by_name']?.toString(),
      transportAmount: json['transport_amount'] == null
          ? null
          : double.tryParse('${json['transport_amount']}'),
      dealer: dealerJson is Map<String, dynamic>
          ? OrderDealer.fromJson(dealerJson)
          : (dealerJson is Map
              ? OrderDealer.fromJson(Map<String, dynamic>.from(dealerJson))
              : null),
      items: rawItems is List
          ? rawItems
              .whereType<Map>()
              .map(
                (item) => OrderDetailItem.fromJson(
                  Map<String, dynamic>.from(item),
                ),
              )
              .toList()
          : const [],
      timelineSteps: rawTimeline is List
          ? rawTimeline
              .whereType<Map>()
              .map(
                (step) => OrderTimelineStep.fromApi(
                  Map<String, dynamic>.from(step),
                ),
              )
              .toList()
          : const [],
    );
  }

  List<OrderTimelineStep> get timeline => timelineSteps.isNotEmpty
      ? timelineSteps
      : OrderTimelineStep.build(
          status,
          rejectedByRole: rejectedByRole,
        );
}

class OrderTimelineStep {
  const OrderTimelineStep({
    required this.label,
    required this.isComplete,
    required this.isCurrent,
    required this.isRejected,
    this.actor,
    this.actorRole,
    this.at,
    this.statusText,
    this.remark,
  });

  final String label;
  final bool isComplete;
  final bool isCurrent;
  final bool isRejected;
  final String? actor;
  final String? actorRole;
  final String? at;
  final String? statusText;
  final String? remark;

  factory OrderTimelineStep.fromApi(Map<String, dynamic> json) {
    final completed = json['completed'] == true;
    final isRejection = json['is_rejection'] == true;
    final isCurrent = json['is_current'] == true || (!completed || isRejection);

    return OrderTimelineStep(
      label: json['label']?.toString() ?? '',
      actor: json['actor']?.toString(),
      actorRole: json['actor_role']?.toString(),
      at: json['at']?.toString(),
      statusText: json['status_text']?.toString(),
      remark: json['remark']?.toString(),
      isComplete: completed,
      isCurrent: isCurrent,
      isRejected: isRejection,
    );
  }

  static List<OrderTimelineStep> build(
    String status, {
    String? rejectedByRole,
  }) {
    final value = OrderStatusRules.normalize(status);

    if (OrderStatusRules.isRejectedBucket(value)) {
      return [
        const OrderTimelineStep(
          label: 'Order Placed',
          isComplete: true,
          isCurrent: false,
          isRejected: false,
        ),
        OrderTimelineStep(
          label: OrderStatusRules.badgeLabel(
            status,
            rejectedByRole: rejectedByRole,
          ),
          isComplete: true,
          isCurrent: true,
          isRejected: true,
        ),
      ];
    }

    final approvedDone = {
      'approved',
      'pending_for_billing',
      'billed',
      'dispatched',
      'delivered',
    }.contains(value);
    final sentDone = {
      'pending_for_billing',
      'billed',
      'dispatched',
      'delivered',
    }.contains(value);
    final billedDone = {'billed', 'dispatched', 'delivered'}.contains(value);
    final dispatchedDone = {'dispatched', 'delivered'}.contains(value);

    return [
      const OrderTimelineStep(
        label: 'Order Placed',
        isComplete: true,
        isCurrent: false,
        isRejected: false,
      ),
      OrderTimelineStep(
        label: 'Approved by Sales Manager',
        isComplete: approvedDone,
        isCurrent: !approvedDone,
        isRejected: false,
        statusText: approvedDone ? null : 'Pending',
      ),
      OrderTimelineStep(
        label: 'Sent for Bill',
        isComplete: sentDone,
        isCurrent: approvedDone && !sentDone,
        isRejected: false,
        statusText: sentDone
            ? (billedDone ? null : 'Pending for Billing')
            : 'Pending',
      ),
      OrderTimelineStep(
        label: 'Billed',
        isComplete: billedDone,
        isCurrent: sentDone && !billedDone,
        isRejected: false,
        statusText: billedDone ? null : 'Pending',
      ),
      OrderTimelineStep(
        label: 'Dispatched',
        isComplete: dispatchedDone,
        isCurrent: billedDone && !dispatchedDone,
        isRejected: false,
        statusText: dispatchedDone ? null : 'Pending',
      ),
    ];
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
