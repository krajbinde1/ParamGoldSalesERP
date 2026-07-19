import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_metric_card.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../models/collection_item.dart';

class CollectionSummaryCard extends StatelessWidget {
  const CollectionSummaryCard({
    super.key,
    required this.label,
    required this.value,
    required this.color,
    this.icon = Icons.payments_rounded,
  });

  final String label;
  final String value;
  final Color color;
  final IconData icon;

  @override
  Widget build(BuildContext context) => SizedBox(
    height: 110,
    child: PgMetricCard(
      title: label,
      value: value,
      icon: icon,
      gradient: [color, color.withValues(alpha: 0.7)],
    ),
  );
}

class CollectionStatusBadge extends StatelessWidget {
  const CollectionStatusBadge({super.key, required this.status});
  final String status;

  String get _label => switch (status) {
    'received' => 'Received',
    'not_received' => 'Not Received',
    _ => 'Pending',
  };

  PgStatusTone get _tone => switch (status) {
    'received' => PgStatusTone.paid,
    'not_received' => PgStatusTone.rejected,
    _ => PgStatusTone.pending,
  };

  @override
  Widget build(BuildContext context) =>
      PgStatusBadge(label: _label, tone: _tone);
}

class RecentCollectionTile extends StatelessWidget {
  const RecentCollectionTile({super.key, required this.collection, this.onTap});

  final CollectionItem collection;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => PgCard(
    onTap: onTap,
    margin: const EdgeInsets.only(bottom: AppSpacing.sm),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                collection.dealerName,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            CollectionStatusBadge(status: collection.status),
          ],
        ),
        const SizedBox(height: AppSpacing.sm),
        Row(
          children: [
            Expanded(
              child: Text(
                DateFormat('d MMM yyyy').format(collection.collectionDate),
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
              ),
            ),
            Text(
              NumberFormat.currency(
                locale: 'en_IN',
                symbol: '₹',
                decimalDigits: 2,
              ).format(collection.amount),
              style: Theme.of(context).textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ),
      ],
    ),
  );
}
