import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_metric_card.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../models/order.dart';
import '../models/order_detail.dart';

class OrderSummaryCard extends StatelessWidget {
  const OrderSummaryCard({
    super.key,
    required this.label,
    required this.value,
    required this.color,
    this.icon = const Icon(Icons.receipt_long_rounded),
    this.onTap,
  });

  final String label;
  final String value;
  final Color color;
  final Widget icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => SizedBox(
    height: 110,
    child: PgMetricCard(
      title: label,
      value: value,
      icon: icon,
      gradient: [color, color.withValues(alpha: 0.7)],
      onTap: onTap,
    ),
  );
}

class OrderStatusBadge extends StatelessWidget {
  const OrderStatusBadge({super.key, required this.status});
  final String status;

  @override
  Widget build(BuildContext context) => PgStatusBadge(
    label: OrderStatusRules.badgeLabel(status),
    tone: _toPgTone(OrderStatusRules.badgeTone(status)),
  );

  static PgStatusTone _toPgTone(OrderBadgeTone tone) => switch (tone) {
    OrderBadgeTone.pending => PgStatusTone.pending,
    OrderBadgeTone.approved => PgStatusTone.approved,
    OrderBadgeTone.dispatched => PgStatusTone.dispatched,
    OrderBadgeTone.rejected => PgStatusTone.rejected,
  };
}

class RecentOrderTile extends StatelessWidget {
  const RecentOrderTile({super.key, required this.order, this.onTap});
  final Order order;
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
                order.dealerName,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            OrderStatusBadge(status: order.status),
          ],
        ),
        const SizedBox(height: AppSpacing.sm),
        Text(order.orderNo, style: Theme.of(context).textTheme.bodyMedium),
        const SizedBox(height: 4),
        Row(
          children: [
            Expanded(
              child: Text(
                DateFormat('d MMM yyyy').format(order.orderDate),
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
              ).format(order.amount),
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

class OrderListTile extends StatelessWidget {
  const OrderListTile({super.key, required this.order, this.onTap});
  final Order order;
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
                order.orderNo,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            OrderStatusBadge(status: order.status),
          ],
        ),
        const SizedBox(height: AppSpacing.sm),
        Text(order.dealerName, style: Theme.of(context).textTheme.bodyLarge),
        const SizedBox(height: 4),
        Row(
          children: [
            Expanded(
              child: Text(
                DateFormat('d MMM yyyy').format(order.orderDate),
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
              ).format(order.amount),
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

class OrderTimelineRow extends StatelessWidget {
  const OrderTimelineRow({super.key, required this.step, required this.isLast});
  final OrderTimelineStep step;
  final bool isLast;

  @override
  Widget build(BuildContext context) {
    final isCompleted = step.isComplete && !step.isRejected;
    final isActive = step.isCurrent;

    return PgTimelineStep(
      title: step.label,
      subtitle: '',
      isCompleted: isCompleted,
      isActive: isActive,
      isLast: isLast,
    );
  }
}
