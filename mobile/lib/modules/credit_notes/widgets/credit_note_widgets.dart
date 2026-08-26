import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../orders/models/order_detail.dart';
import '../../orders/widgets/order_widgets.dart';
import '../models/credit_note.dart';

PgStatusTone creditNoteStatusTone(String status) {
  return switch (status) {
    'approved' => PgStatusTone.approved,
    'completed' => PgStatusTone.paid,
    'rejected' => PgStatusTone.rejected,
    _ => PgStatusTone.pending,
  };
}

class CreditNoteListTile extends StatelessWidget {
  const CreditNoteListTile({
    super.key,
    required this.note,
    this.onTap,
    this.showEmployee = false,
  });

  final CreditNoteListItem note;
  final VoidCallback? onTap;
  final bool showEmployee;

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 2,
    );
    final date = note.creditNoteDate;
    final subtitle = [
      if (note.typeLabel.isNotEmpty) note.typeLabel,
      if (date != null) DateFormat('d MMM yyyy').format(date),
      if (showEmployee && (note.employeeName ?? '').isNotEmpty)
        note.employeeName,
    ].join(' • ');

    return PgCard(
      onTap: onTap,
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  note.creditNoteNo.isEmpty ? note.dealerName : note.creditNoteNo,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
              PgStatusBadge(
                label: note.statusLabel.isEmpty ? note.status : note.statusLabel,
                tone: creditNoteStatusTone(note.status),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            note.dealerName,
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          const SizedBox(height: AppSpacing.sm),
          Row(
            children: [
              Expanded(
                child: Text(
                  subtitle,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
                ),
              ),
              Text(
                currency.format(note.amount),
                style: Theme.of(
                  context,
                ).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class CreditNoteTimeline extends StatelessWidget {
  const CreditNoteTimeline({super.key, required this.steps});

  final List<OrderTimelineStep> steps;

  @override
  Widget build(BuildContext context) {
    if (steps.isEmpty) {
      return const SizedBox.shrink();
    }

    return PgCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Approval Timeline',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: AppSpacing.md),
          ...List.generate(steps.length, (index) {
            final step = steps[index];
            return OrderTimelineRow(
              step: step,
              isLast: index == steps.length - 1,
            );
          }),
        ],
      ),
    );
  }
}
