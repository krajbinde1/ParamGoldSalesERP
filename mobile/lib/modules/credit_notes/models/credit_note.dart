import '../../orders/models/order_dealer.dart';
import '../../orders/models/order_detail.dart';
import '../../orders/models/order_line_item.dart';

class CreditNoteListItem {
  const CreditNoteListItem({
    required this.id,
    required this.creditNoteNo,
    required this.type,
    required this.typeLabel,
    required this.dealerName,
    required this.amount,
    required this.status,
    required this.statusLabel,
    this.employeeName,
    this.billReference,
    this.creditNoteDate,
    this.rejectionRemark,
    this.moveTo,
    this.moveToLabel,
    this.destinationDealerName,
    this.linkedOrderNo,
  });

  final int id;
  final String creditNoteNo;
  final String type;
  final String typeLabel;
  final String dealerName;
  final double amount;
  final String status;
  final String statusLabel;
  final String? employeeName;
  final String? billReference;
  final DateTime? creditNoteDate;
  final String? rejectionRemark;
  final String? moveTo;
  final String? moveToLabel;
  final String? destinationDealerName;
  final String? linkedOrderNo;

  factory CreditNoteListItem.fromJson(Map<String, dynamic> json) =>
      CreditNoteListItem(
        id: int.tryParse('${json['id'] ?? ''}') ?? 0,
        creditNoteNo: json['credit_note_no']?.toString() ?? '',
        type: json['type']?.toString() ?? '',
        typeLabel: json['type_label']?.toString() ?? '',
        dealerName: json['dealer_name']?.toString() ?? '-',
        amount: double.tryParse('${json['amount'] ?? 0}') ?? 0,
        status: json['status']?.toString() ?? '',
        statusLabel: json['status_label']?.toString() ?? '',
        employeeName: json['employee_name']?.toString(),
        billReference: json['bill_reference']?.toString(),
        creditNoteDate: _parseDate(json['credit_note_date']),
        rejectionRemark: json['rejection_remark']?.toString(),
        moveTo: json['move_to']?.toString(),
        moveToLabel: json['move_to_label']?.toString(),
        destinationDealerName: json['destination_dealer_name']?.toString(),
        linkedOrderNo: json['linked_order_no']?.toString(),
      );

  static DateTime? _parseDate(Object? value) {
    if (value == null || '$value'.trim().isEmpty) return null;
    return DateTime.tryParse(value.toString());
  }
}

class CreditNoteLine {
  const CreditNoteLine({
    required this.productId,
    required this.productName,
    required this.quantity,
    required this.amount,
    this.productCode,
    this.uom,
    this.rate,
    this.originalRate,
    this.revisedRate,
    this.reason,
    this.caseQuantity,
    this.nosPerCase,
    this.totalQuantityNos,
    this.ratePerNo,
    this.rateType,
    this.discountPercentage,
    this.gstPercentage,
    this.finalAmount,
  });

  final int productId;
  final String productName;
  final String? productCode;
  final String? uom;
  final double quantity;
  final double? rate;
  final double? originalRate;
  final double? revisedRate;
  final double amount;
  final String? reason;
  final int? caseQuantity;
  final int? nosPerCase;
  final int? totalQuantityNos;
  final double? ratePerNo;
  final String? rateType;
  final double? discountPercentage;
  final double? gstPercentage;
  final double? finalAmount;

  factory CreditNoteLine.fromJson(Map<String, dynamic> json) => CreditNoteLine(
    productId: int.tryParse('${json['product_id'] ?? ''}') ?? 0,
    productName: json['product_name']?.toString() ?? '—',
    productCode: json['product_code']?.toString(),
    uom: json['uom']?.toString(),
    quantity: double.tryParse('${json['quantity'] ?? 0}') ?? 0,
    rate: json['rate'] == null ? null : double.tryParse('${json['rate']}'),
    originalRate: json['original_rate'] == null
        ? null
        : double.tryParse('${json['original_rate']}'),
    revisedRate: json['revised_rate'] == null
        ? null
        : double.tryParse('${json['revised_rate']}'),
    amount: double.tryParse('${json['amount'] ?? 0}') ?? 0,
    reason: json['reason']?.toString(),
    caseQuantity: json['case_quantity'] == null
        ? null
        : int.tryParse('${json['case_quantity']}'),
    nosPerCase: json['nos_per_case'] == null
        ? null
        : int.tryParse('${json['nos_per_case']}'),
    totalQuantityNos: json['total_quantity_nos'] == null
        ? null
        : int.tryParse('${json['total_quantity_nos']}'),
    ratePerNo: json['rate_per_no'] == null
        ? null
        : double.tryParse('${json['rate_per_no']}'),
    rateType: json['rate_type']?.toString(),
    discountPercentage: json['discount_percentage'] == null
        ? null
        : double.tryParse('${json['discount_percentage']}'),
    gstPercentage: json['gst_percentage'] == null
        ? null
        : double.tryParse('${json['gst_percentage']}'),
    finalAmount: json['final_amount'] == null
        ? null
        : double.tryParse('${json['final_amount']}'),
  );

  Map<String, dynamic> toPayload(String type) {
    final payload = <String, dynamic>{
      'product_id': productId,
      if ((reason ?? '').trim().isNotEmpty) 'reason': reason!.trim(),
    };
    if (type == 'rate_difference') {
      payload['quantity'] = quantity;
      payload['original_rate'] = originalRate ?? 0;
      payload['revised_rate'] = revisedRate ?? 0;
    } else {
      payload['case_quantity'] = caseQuantity ?? 1;
      payload['rate_per_no'] = ratePerNo ?? rate ?? 0;
      payload['rate_type'] = rateType ?? 'price_list';
      payload['discount_value'] = discountPercentage ?? 0;
      if (gstPercentage != null) payload['gst_percentage'] = gstPercentage;
    }
    return payload;
  }
}

class CreditNoteLinkedOrder {
  const CreditNoteLinkedOrder({
    required this.id,
    required this.orderNo,
    required this.status,
    this.statusLabel,
    this.dealerName,
  });

  final int id;
  final String orderNo;
  final String status;
  final String? statusLabel;
  final String? dealerName;

  factory CreditNoteLinkedOrder.fromJson(Map<String, dynamic> json) =>
      CreditNoteLinkedOrder(
        id: int.tryParse('${json['id'] ?? ''}') ?? 0,
        orderNo: json['order_no']?.toString() ?? '',
        status: json['status']?.toString() ?? '',
        statusLabel: json['status_label']?.toString(),
        dealerName: json['dealer_name']?.toString(),
      );
}

class CreditNoteDetail {
  const CreditNoteDetail({
    required this.id,
    required this.creditNoteNo,
    required this.type,
    required this.typeLabel,
    required this.status,
    required this.statusLabel,
    required this.amount,
    required this.items,
    required this.timeline,
    required this.canEdit,
    this.billReference,
    this.creditNoteDate,
    this.remarks,
    this.supportingDocumentUrl,
    this.supportingDocumentIsImage = false,
    this.employeeName,
    this.dealer,
    this.rejectionRemark,
    this.approvalRemark,
    this.completionRemark,
    this.moveTo,
    this.moveToLabel,
    this.destinationDealer,
    this.linkedOrder,
  });

  final int id;
  final String creditNoteNo;
  final String type;
  final String typeLabel;
  final String status;
  final String statusLabel;
  final double amount;
  final String? billReference;
  final DateTime? creditNoteDate;
  final String? remarks;
  final String? supportingDocumentUrl;
  final bool supportingDocumentIsImage;
  final String? employeeName;
  final OrderDealer? dealer;
  final String? rejectionRemark;
  final String? approvalRemark;
  final String? completionRemark;
  final bool canEdit;
  final List<CreditNoteLine> items;
  final List<OrderTimelineStep> timeline;
  final String? moveTo;
  final String? moveToLabel;
  final OrderDealer? destinationDealer;
  final CreditNoteLinkedOrder? linkedOrder;

  factory CreditNoteDetail.fromJson(Map<String, dynamic> json) {
    final dealerJson = json['dealer'];
    final destinationJson = json['destination_dealer'];
    final linkedJson = json['linked_order'];
    return CreditNoteDetail(
      id: int.tryParse('${json['id'] ?? ''}') ?? 0,
      creditNoteNo: json['credit_note_no']?.toString() ?? '',
      type: json['type']?.toString() ?? '',
      typeLabel: json['type_label']?.toString() ?? '',
      status: json['status']?.toString() ?? '',
      statusLabel: json['status_label']?.toString() ?? '',
      amount: double.tryParse('${json['amount'] ?? 0}') ?? 0,
      billReference: json['bill_reference']?.toString(),
      creditNoteDate: json['credit_note_date'] == null
          ? null
          : DateTime.tryParse(json['credit_note_date'].toString()),
      remarks: json['remarks']?.toString(),
      supportingDocumentUrl: json['supporting_document_url']?.toString(),
      supportingDocumentIsImage: json['supporting_document_is_image'] == true,
      employeeName: json['employee_name']?.toString(),
      dealer: dealerJson is Map
          ? OrderDealer.fromJson(Map<String, dynamic>.from(dealerJson))
          : null,
      rejectionRemark: json['rejection_remark']?.toString(),
      approvalRemark: json['approval_remark']?.toString(),
      completionRemark: json['completion_remark']?.toString(),
      canEdit: json['can_edit'] == true,
      moveTo: json['move_to']?.toString(),
      moveToLabel: json['move_to_label']?.toString(),
      destinationDealer: destinationJson is Map
          ? OrderDealer.fromJson(Map<String, dynamic>.from(destinationJson))
          : null,
      linkedOrder: linkedJson is Map
          ? CreditNoteLinkedOrder.fromJson(
              Map<String, dynamic>.from(linkedJson),
            )
          : null,
      items: (json['items'] as List? ?? const [])
          .whereType<Map>()
          .map(
            (item) => CreditNoteLine.fromJson(Map<String, dynamic>.from(item)),
          )
          .toList(),
      timeline: (json['timeline'] as List? ?? const [])
          .whereType<Map>()
          .map(
            (step) =>
                OrderTimelineStep.fromApi(Map<String, dynamic>.from(step)),
          )
          .toList(),
    );
  }

  OrderLineItem? lineAsOrderItem(CreditNoteLine item) {
    if (item.productId < 1) return null;
    return OrderLineItem.fromOrderJson({
      'product_id': item.productId,
      'product_name': item.productName,
      'product_code': item.productCode,
      'case_quantity': item.caseQuantity ?? 1,
      'nos_per_case': item.nosPerCase ?? 1,
      'rate_per_no': item.ratePerNo ?? item.rate,
      'rate': item.rate,
      'original_dealer_price': item.ratePerNo ?? item.rate,
      'discount_percentage': item.discountPercentage,
      'gst_percentage': item.gstPercentage,
      'rate_type': item.rateType,
    });
  }
}
