import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_metric_card.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../models/ta_da_claim_item.dart';

class TaDaClaimSummaryCard extends StatelessWidget {
  const TaDaClaimSummaryCard({
    super.key,
    required this.label,
    required this.value,
    required this.color,
    this.icon = const Icon(Icons.receipt_long_rounded),
  });

  final String label;
  final String value;
  final Color color;
  final Widget icon;

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

class TaDaClaimStatusBadge extends StatelessWidget {
  const TaDaClaimStatusBadge({super.key, required this.status});
  final String status;

  String get _label => switch (status) {
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'paid' => 'Paid',
    _ => status,
  };

  @override
  Widget build(BuildContext context) => PgStatusBadge(
    label: _label,
    tone: PgStatusRules.claimTone(status),
  );
}

class RecentTaDaClaimTile extends StatelessWidget {
  const RecentTaDaClaimTile({
    super.key,
    required this.claim,
    required this.currency,
    required this.onTap,
  });

  final TaDaClaimItem claim;
  final NumberFormat currency;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => PgCard(
    onTap: onTap,
    margin: const EdgeInsets.only(bottom: AppSpacing.sm),
    child: Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                DateFormat('d MMM yyyy').format(claim.claimDate),
                style: Theme.of(context).textTheme.titleSmall,
              ),
              const SizedBox(height: 4),
              Text(
                claim.route,
                style: Theme.of(context).textTheme.bodyMedium,
              ),
              Text(
                '${claim.travelKm.toStringAsFixed(2)} KM • ${currency.format(claim.totalAmount)}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
              ),
            ],
          ),
        ),
        TaDaClaimStatusBadge(status: claim.status),
      ],
    ),
  );
}
