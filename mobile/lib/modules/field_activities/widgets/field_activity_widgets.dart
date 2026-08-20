import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_metric_card.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../models/field_activity_item.dart';

class FieldActivitySummaryCard extends StatelessWidget {
  const FieldActivitySummaryCard({
    super.key,
    required this.label,
    required this.value,
    required this.color,
    this.icon = const Icon(Icons.route_rounded),
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

class FieldActivityStatusBadge extends StatelessWidget {
  const FieldActivityStatusBadge({super.key, required this.status});
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

class RecentFieldActivityTile extends StatelessWidget {
  const RecentFieldActivityTile({
    super.key,
    required this.activity,
    required this.onTap,
  });

  final FieldActivityItem activity;
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
                activity.farmerName,
                style: Theme.of(context).textTheme.titleSmall,
              ),
              const SizedBox(height: 4),
              Text(
                [
                  if ((activity.district ?? '').isNotEmpty) activity.district!,
                  activity.village,
                  activity.taluka,
                ].where((part) => part.isNotEmpty).join(', '),
                style: Theme.of(context).textTheme.bodyMedium,
              ),
              if ((activity.cropName ?? '').isNotEmpty)
                Text(
                  'Crop: ${activity.cropName}',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              Text(
                '${DateFormat('d MMM yyyy').format(activity.activityDate)} • ${activity.activityTime}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
              ),
            ],
          ),
        ),
        FieldActivityStatusBadge(status: activity.status),
      ],
    ),
  );
}
