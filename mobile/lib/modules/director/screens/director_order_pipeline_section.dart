import 'package:flutter/material.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../api/director_api.dart';

/// Director Order Pipeline — counts and routes unchanged.
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
      (
        'Placed',
        data.placedOrders,
        '/director/orders?status=pending_approval',
        const Icon(Icons.pending_actions_rounded),
      ),
      (
        'Approved',
        data.approvedOrders,
        '/director/orders?status=approved',
        const Icon(Icons.check_circle_outline),
      ),
      (
        'Sent for Bill',
        data.sentForBillOrders,
        '/director/orders?status=pending_for_billing',
        const Icon(Icons.receipt_long_outlined),
      ),
      (
        'Billed',
        data.billedOrders,
        '/director/orders?status=billed',
        const Icon(Icons.payments_outlined),
      ),
      (
        'Dispatched',
        data.dispatchedOrders,
        '/director/orders?status=dispatched',
        const Icon(Icons.local_shipping_outlined),
      ),
    ];
    final extras = [
      (
        'On Hold',
        data.onHoldOrders,
        '/director/orders?status=on_hold',
        const Icon(Icons.pause_circle_outline),
      ),
      (
        'Returned to Manager',
        data.revertedOrders,
        '/director/orders?status=reverted_to_manager',
        const Icon(Icons.undo_rounded),
      ),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _TotalPendingCard(count: data.pendingOrders),
        const SizedBox(height: 14),
        Text(
          'Order Pipeline',
          style: Theme.of(context).textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.w800,
                letterSpacing: -0.2,
                color: AppColors.textPrimary,
              ),
        ),
        const SizedBox(height: 8),
        PgCard(
          padding: const EdgeInsets.fromLTRB(8, 14, 8, 12),
          child: _ConnectedPipeline(
            stages: stages,
            onOpen: onOpen,
          ),
        ),
        const SizedBox(height: 8),
        Center(
          child: Text(
            'Tap on any status to view orders',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
                  color: AppColors.textMuted,
                  fontWeight: FontWeight.w600,
                  fontSize: 11.5,
                  letterSpacing: 0.1,
                ),
          ),
        ),
        const SizedBox(height: 12),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            for (var i = 0; i < extras.length; i++) ...[
              if (i > 0) const SizedBox(width: 8),
              Expanded(
                child: _ExceptionCard(
                  label: extras[i].$1,
                  count: extras[i].$2,
                  icon: extras[i].$4,
                  onTap: () => onOpen(extras[i].$3),
                ),
              ),
            ],
          ],
        ),
      ],
    );
  }
}

class _ConnectedPipeline extends StatelessWidget {
  const _ConnectedPipeline({
    required this.stages,
    required this.onOpen,
  });

  final List<(String, int, String, Widget)> stages;
  final Future<void> Function(String path) onOpen;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final compact = constraints.maxWidth < 340;
        final circle = compact ? 32.0 : 36.0;
        final stageCount = stages.length;
        final stageWidth = constraints.maxWidth / stageCount;

        return Stack(
          children: [
            Positioned(
              top: circle / 2 - 0.75,
              left: stageWidth / 2,
              right: stageWidth / 2,
              child: Container(
                height: 1.5,
                decoration: BoxDecoration(
                  color: AppColors.primary.withValues(alpha: 0.18),
                  borderRadius: BorderRadius.circular(99),
                ),
              ),
            ),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (final stage in stages)
                  Expanded(
                    child: _PipelineStage(
                      label: stage.$1,
                      count: stage.$2,
                      icon: stage.$4,
                      circleSize: circle,
                      compact: compact,
                      onTap: () => onOpen(stage.$3),
                    ),
                  ),
              ],
            ),
          ],
        );
      },
    );
  }
}

class _TotalPendingCard extends StatelessWidget {
  const _TotalPendingCard({required this.count});

  final int count;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.primary.withValues(alpha: 0.14)),
        boxShadow: [
          BoxShadow(
            color: AppColors.primary.withValues(alpha: 0.08),
            blurRadius: 16,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      clipBehavior: Clip.antiAlias,
      child: IntrinsicHeight(
        child: Row(
          children: [
            Container(width: 4, color: AppColors.primary),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(14, 14, 14, 14),
                child: Row(
                  children: [
                    Container(
                      width: 46,
                      height: 46,
                      decoration: BoxDecoration(
                        color: AppColors.primary.withValues(alpha: 0.10),
                        borderRadius: BorderRadius.circular(13),
                      ),
                      child: const Icon(
                        Icons.pending_actions_rounded,
                        color: AppColors.primary,
                        size: 23,
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Total Pending Orders',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.labelMedium?.copyWith(
                              color: AppColors.textSecondary,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          const SizedBox(height: 3),
                          FittedBox(
                            fit: BoxFit.scaleDown,
                            alignment: Alignment.centerLeft,
                            child: Text(
                              '$count',
                              maxLines: 1,
                              style: theme.textTheme.headlineSmall?.copyWith(
                                fontWeight: FontWeight.w800,
                                letterSpacing: -0.7,
                                height: 1.05,
                                color: AppColors.primary,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PipelineStage extends StatelessWidget {
  const _PipelineStage({
    required this.label,
    required this.count,
    required this.icon,
    required this.circleSize,
    required this.compact,
    required this.onTap,
  });

  final String label;
  final int count;
  final Widget icon;
  final double circleSize;
  final bool compact;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 2, vertical: 2),
          child: Column(
            children: [
              Container(
                width: circleSize,
                height: circleSize,
                decoration: BoxDecoration(
                  color: Colors.white,
                  shape: BoxShape.circle,
                  border: Border.all(
                    color: AppColors.primary.withValues(alpha: 0.22),
                    width: 1.4,
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: AppColors.primary.withValues(alpha: 0.08),
                      blurRadius: 8,
                      offset: const Offset(0, 2),
                    ),
                  ],
                ),
                child: IconTheme(
                  data: IconThemeData(
                    size: compact ? 15 : 17,
                    color: AppColors.primary,
                  ),
                  child: Center(child: icon),
                ),
              ),
              const SizedBox(height: 6),
              FittedBox(
                fit: BoxFit.scaleDown,
                child: Text(
                  '$count',
                  maxLines: 1,
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w800,
                        letterSpacing: -0.3,
                        height: 1.0,
                        fontSize: compact ? 14 : 16,
                        color: AppColors.textPrimary,
                      ),
                ),
              ),
              const SizedBox(height: 4),
              SizedBox(
                height: compact ? 24 : 26,
                child: Text(
                  label,
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                        color: AppColors.textSecondary,
                        fontWeight: FontWeight.w600,
                        fontSize: compact ? 9.5 : 10.5,
                        height: 1.15,
                      ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ExceptionCard extends StatelessWidget {
  const _ExceptionCard({
    required this.label,
    required this.count,
    required this.icon,
    required this.onTap,
  });

  final String label;
  final int count;
  final Widget icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    const tone = AppColors.warning;
    return PgCard(
      onTap: onTap,
      padding: const EdgeInsets.fromLTRB(10, 10, 8, 10),
      child: Row(
        children: [
          Container(
            width: 30,
            height: 30,
            decoration: BoxDecoration(
              color: tone.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(8),
            ),
            child: IconTheme(
              data: const IconThemeData(size: 16, color: tone),
              child: Center(child: icon),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(
                    '$count',
                    maxLines: 1,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w800,
                          letterSpacing: -0.2,
                          height: 1.05,
                          color: tone,
                        ),
                  ),
                ),
                const SizedBox(height: 1),
                Text(
                  label,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                        color: AppColors.textSecondary,
                        fontWeight: FontWeight.w600,
                        fontSize: 10.5,
                        height: 1.15,
                      ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
