import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';

/// Shared Order Info block for Employee, Manager, and Production Supervisor.
///
/// Shows only the common identity fields. Workflow / billing / dispatch details
/// belong in Timeline or role-specific action sections.
class OrderInfoCard extends StatelessWidget {
  const OrderInfoCard({
    super.key,
    required this.orderDate,
    required this.createdBy,
    required this.dealerName,
    required this.dealerVillage,
    this.remarks,
    this.title = 'Order Info',
    this.showRemarks = true,
    this.createdByLabel = 'Created By',
  });

  final String orderDate;
  final String createdBy;
  final String dealerName;
  final String dealerVillage;
  final String? remarks;
  final String title;
  final bool showRemarks;
  final String createdByLabel;

  static final DateFormat _defaultDate = DateFormat('dd MMM yyyy');

  /// Build from a typical order API map (+ optional nested dealer map).
  factory OrderInfoCard.fromOrderMap(
    Map<String, dynamic> order, {
    Map<String, dynamic>? dealer,
    bool showRemarks = true,
    String createdByLabel = 'Created By',
    DateFormat? dateFormat,
  }) {
    final dealerMap = dealer ??
        (order['dealer'] is Map
            ? Map<String, dynamic>.from(order['dealer'] as Map)
            : null);

    final village = (dealerMap?['village'] ?? order['dealer_village'] ?? '')
        .toString()
        .trim();
    final remarksRaw = (order['remarks'] ?? order['remark'] ?? '').toString();

    return OrderInfoCard(
      orderDate: _formatOrderDate(
        order['order_date'] ?? order['created_at'],
        dateFormat ?? _defaultDate,
      ),
      createdBy: (order['employee_name'] ??
              order['created_by_name'] ??
              order['sales_employee_name'] ??
              '')
          .toString()
          .trim()
          .ifEmpty('-'),
      dealerName: (dealerMap?['firm_name'] ?? order['dealer_name'] ?? '')
          .toString()
          .trim()
          .ifEmpty('-'),
      dealerVillage: village.isEmpty ? '-' : village,
      remarks: remarksRaw.trim().isEmpty ? null : remarksRaw.trim(),
      showRemarks: showRemarks,
      createdByLabel: createdByLabel,
    );
  }

  static String _formatOrderDate(Object? raw, DateFormat format) {
    if (raw == null) return '-';
    if (raw is DateTime) return format.format(raw.toLocal());
    final parsed = DateTime.tryParse(raw.toString());
    if (parsed == null) {
      final text = raw.toString().trim();
      return text.isEmpty ? '-' : text;
    }
    return format.format(parsed.toLocal());
  }

  @override
  Widget build(BuildContext context) {
    final remarksValue = (remarks ?? '').trim();

    return PgCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: AppSpacing.sm),
          PgInvoiceRow(label: 'Order Date', value: orderDate),
          PgInvoiceRow(label: createdByLabel, value: createdBy),
          PgInvoiceRow(label: 'Dealer Name', value: dealerName),
          PgInvoiceRow(label: 'Dealer Village', value: dealerVillage),
          if (showRemarks)
            PgInvoiceRow(
              label: 'Remarks',
              value: remarksValue.isEmpty ? '—' : remarksValue,
            ),
        ],
      ),
    );
  }
}

extension on String {
  String ifEmpty(String fallback) => trim().isEmpty ? fallback : this;
}
