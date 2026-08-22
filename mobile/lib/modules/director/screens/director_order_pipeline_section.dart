import 'package:flutter/material.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../api/director_api.dart';

/// Existing Director Order Pipeline chips — counts and routes unchanged.
class DirectorOrderPipelineSection extends StatelessWidget {
  const DirectorOrderPipelineSection({
    super.key,
    required this.data,
    required this.onOpen,
  });

  final DirectorDashboardData data;
  final Future<void> Function(String path) onOpen;

  @override
  Widget build(BuildContext context) {
    final stages = [
      ('Placed', data.placedOrders, '/director/orders?status=pending_approval'),
      ('Approved', data.approvedOrders, '/director/orders?status=approved'),
      ('Sent for Bill', data.sentForBillOrders, '/director/orders?status=pending_for_billing'),
      ('Billed', data.billedOrders, '/director/orders?status=billed'),
      ('Dispatched', data.dispatchedOrders, '/director/orders?status=dispatched'),
    ];
    final extras = [
      ('On Hold', data.onHoldOrders, '/director/orders?status=on_hold'),
      ('Returned to Manager', data.revertedOrders, '/director/orders?status=reverted_to_manager'),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const PgSectionHeader(title: 'Order Pipeline'),
        PgCard(
          padding: const EdgeInsets.all(12),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (var i = 0; i < stages.length; i++) ...[
                _PipelineChip(
                  label: stages[i].$1,
                  count: stages[i].$2,
                  onTap: () => onOpen(stages[i].$3),
                ),
                if (i < stages.length - 1)
                  Padding(
                    padding: const EdgeInsets.only(top: 10),
                    child: Icon(
                      Icons.arrow_forward_rounded,
                      size: 14,
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
                  ),
              ],
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.sm),
        Row(
          children: [
            for (final extra in extras)
              Expanded(
                child: Padding(
                  padding: EdgeInsets.only(
                    right: extra == extras.last ? 0 : 8,
                  ),
                  child: _PipelineChip(
                    label: extra.$1,
                    count: extra.$2,
                    tone: AppColors.warning,
                    onTap: () => onOpen(extra.$3),
                  ),
                ),
              ),
          ],
        ),
      ],
    );
  }
}

class _PipelineChip extends StatelessWidget {
  const _PipelineChip({
    required this.label,
    required this.count,
    required this.onTap,
    this.tone,
  });

  final String label;
  final int count;
  final VoidCallback onTap;
  final Color? tone;

  @override
  Widget build(BuildContext context) {
    final color = tone ?? AppColors.primary;
    return Material(
      color: color.withValues(alpha: 0.08),
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '$count',
                style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: color,
                    ),
              ),
              Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.labelSmall,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
