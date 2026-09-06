import 'package:flutter/material.dart';

import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_status_badge.dart';

/// Finished-product stock availability with oldest-order-first virtual allocation.
class FinishedProductStockAvailabilitySection extends StatelessWidget {
  const FinishedProductStockAvailabilitySection({
    super.key,
    required this.order,
    this.title = 'Finished Product Stock Availability',
    this.compact = false,
  });

  final Map<String, dynamic> order;
  final String title;
  final bool compact;

  bool get _applies => order['stock_availability_applies'] == true;

  List<Map<String, dynamic>> get _rows {
    final raw = order['stock_availability'];
    if (raw is List) {
      return raw
          .whereType<Map>()
          .map((row) => Map<String, dynamic>.from(row))
          .toList(growable: false);
    }

    final items = (order['line_items'] as List?) ?? (order['items'] as List?) ?? const [];
    final rows = <int, Map<String, dynamic>>{};
    for (final item in items) {
      if (item is! Map) continue;
      final availability = item['stock_availability'];
      if (availability is! Map) continue;
      final mapped = Map<String, dynamic>.from(availability);
      final id = int.tryParse('${mapped['product_id']}') ?? 0;
      if (id > 0) rows[id] = mapped;
    }
    return rows.values.toList(growable: false);
  }

  static String formatQty(dynamic value, [String unit = 'Nos']) {
    final qty = double.tryParse('$value') ?? 0;
    final text = qty == qty.roundToDouble()
        ? qty.round().toString()
        : qty.toStringAsFixed(3).replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
    return '$text $unit';
  }

  static PgStatusTone toneFor(String status) {
    switch (status) {
      case 'available':
        return PgStatusTone.approved;
      case 'partial_stock':
        return PgStatusTone.pending;
      case 'out_of_stock':
        return PgStatusTone.rejected;
      default:
        return PgStatusTone.neutral;
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!_applies) return const SizedBox.shrink();
    final rows = _rows;
    if (rows.isEmpty) return const SizedBox.shrink();

    return PgCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 4),
          if (!compact)
            Text(
              'Oldest pending orders are allocated first. Stock is not deducted until dispatch.',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
            ),
          if (!compact) const SizedBox(height: AppSpacing.md),
          if (compact) const SizedBox(height: AppSpacing.sm),
          ...rows.map((row) => _ProductAvailabilityCard(row: row)),
        ],
      ),
    );
  }
}

class _ProductAvailabilityCard extends StatelessWidget {
  const _ProductAvailabilityCard({required this.row});

  final Map<String, dynamic> row;

  @override
  Widget build(BuildContext context) {
    final unit = '${row['unit'] ?? 'Nos'}'.trim().isEmpty ? 'Nos' : '${row['unit']}';
    final status = '${row['stock_status'] ?? ''}';
    final statusLabel = '${row['stock_status_label'] ?? 'Available'}';
    final shortLabel = row['short_label']?.toString();
    final name = '${row['product_name'] ?? 'Product'}';
    final code = '${row['product_code'] ?? ''}'.trim();

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: DecoratedBox(
        decoration: BoxDecoration(
          border: Border.all(color: AppColors.border),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          name,
                          style: Theme.of(context).textTheme.titleSmall,
                        ),
                        if (code.isNotEmpty)
                          Text(
                            code,
                            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                  color: AppColors.textSecondary,
                                ),
                          ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  PgStatusBadge(
                    label: statusLabel,
                    tone: FinishedProductStockAvailabilitySection.toneFor(status),
                  ),
                ],
              ),
              if (shortLabel != null && shortLabel.isNotEmpty) ...[
                const SizedBox(height: 8),
                PgStatusBadge(
                  label: shortLabel,
                  tone: PgStatusTone.rejected,
                ),
              ],
              const SizedBox(height: AppSpacing.sm),
              _row(context, 'Order Qty', FinishedProductStockAvailabilitySection.formatQty(row['order_qty'], unit)),
              _row(
                context,
                'Current Finished Stock',
                FinishedProductStockAvailabilitySection.formatQty(
                  row['current_finished_stock'],
                  unit,
                ),
              ),
              _row(
                context,
                'Allocated to Earlier Orders',
                FinishedProductStockAvailabilitySection.formatQty(
                  row['allocated_to_earlier_orders'],
                  unit,
                ),
              ),
              _row(
                context,
                'Available for This Order',
                FinishedProductStockAvailabilitySection.formatQty(
                  row['available_for_this_order'],
                  unit,
                ),
              ),
              _row(
                context,
                'Short Qty',
                FinishedProductStockAvailabilitySection.formatQty(row['short_qty'], unit),
                emphasize: (double.tryParse('${row['short_qty']}') ?? 0) > 0,
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _row(
    BuildContext context,
    String label,
    String value, {
    bool emphasize = false,
  }) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
            ),
          ),
          Text(
            value,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: emphasize ? AppColors.error : AppColors.textPrimary,
                ),
          ),
        ],
      ),
    );
  }
}
