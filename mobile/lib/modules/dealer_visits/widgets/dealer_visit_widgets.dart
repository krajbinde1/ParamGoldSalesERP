import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_metric_card.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../models/dealer_visit_item.dart';

class DealerVisitSummaryCard extends StatelessWidget {
  const DealerVisitSummaryCard({
    super.key,
    required this.label,
    required this.value,
    required this.color,
    this.icon = Icons.storefront_rounded,
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

class DealerVisitStatusBadge extends StatelessWidget {
  const DealerVisitStatusBadge({super.key, required this.status});
  final String status;

  String get _label => switch (status) {
    'completed' => 'Completed',
    _ => status,
  };

  @override
  Widget build(BuildContext context) => PgStatusBadge(
    label: _label,
    tone: PgStatusTone.approved,
  );
}

class RecentDealerVisitTile extends StatelessWidget {
  const RecentDealerVisitTile({
    super.key,
    required this.visit,
    required this.onTap,
  });

  final DealerVisitItem visit;
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
                visit.dealerName,
                style: Theme.of(context).textTheme.titleSmall,
              ),
              const SizedBox(height: 4),
              Text(
                '${DateFormat('d MMM yyyy').format(visit.visitDate)} • ${visit.visitTime}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
              ),
            ],
          ),
        ),
        DealerVisitStatusBadge(status: visit.status),
      ],
    ),
  );
}
