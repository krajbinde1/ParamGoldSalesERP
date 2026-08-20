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
    this.icon = const Icon(Icons.storefront_rounded),
  });

  final String label;
  final String value;
  final Color color;
  final Widget icon;

  @override
  Widget build(BuildContext context) => PgMetricCard(
    title: label,
    value: value,
    icon: icon,
    expand: false,
    gradient: [color, color.withValues(alpha: 0.7)],
  );
}

class DealerVisitSummaryGrid extends StatelessWidget {
  const DealerVisitSummaryGrid({super.key, required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        const gap = AppSpacing.sm;
        final twoCol = constraints.maxWidth >= 320;
        if (!twoCol) {
          return Column(
            children: [
              for (var i = 0; i < children.length; i++) ...[
                if (i > 0) const SizedBox(height: gap),
                children[i],
              ],
            ],
          );
        }

        return Column(
          children: [
            for (var i = 0; i < children.length; i += 2) ...[
              if (i > 0) const SizedBox(height: gap),
              IntrinsicHeight(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Expanded(child: children[i]),
                    const SizedBox(width: gap),
                    Expanded(
                      child: i + 1 < children.length
                          ? children[i + 1]
                          : const SizedBox.shrink(),
                    ),
                  ],
                ),
              ),
            ],
          ],
        );
      },
    );
  }
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
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleSmall,
              ),
              const SizedBox(height: 4),
              Text(
                '${DateFormat('d MMM yyyy').format(visit.visitDate)} • ${visit.visitTime}',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(width: 8),
        FittedBox(
          fit: BoxFit.scaleDown,
          child: DealerVisitStatusBadge(status: visit.status),
        ),
      ],
    ),
  );
}
