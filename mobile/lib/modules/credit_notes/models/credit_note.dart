import '../../orders/models/order_dealer.dart';
import '../../orders/models/order_detail.dart';

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
  );

  Map<String, dynamic> toPayload(String type) {
    final payload = <String, dynamic>{
      'product_id': productId,
      'quantity': quantity,
      if ((reason ?? '').trim().isNotEmpty) 'reason': reason!.trim(),
    };
    if (type == 'rate_difference') {
      payload['original_rate'] = originalRate ?? 0;
      payload['revised_rate'] = revisedRate ?? 0;
    } else {
      payload['rate'] = rate ?? 0;
    }
    return payload;
  }
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

  factory CreditNoteDetail.fromJson(Map<String, dynamic> json) {
    final dealerJson = json['dealer'];
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
}
