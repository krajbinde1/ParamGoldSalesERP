import 'package:flutter/material.dart';
import '../../design/app_colors.dart';
import '../../design/app_spacing.dart';
import 'pg_card.dart';
import 'pg_status_badge.dart';

class PgTimelineStep extends StatelessWidget {
  const PgTimelineStep({
    super.key,
    required this.title,
    required this.subtitle,
    required this.isCompleted,
    required this.isActive,
    required this.isLast,
  });

  final String title;
  final String subtitle;
  final bool isCompleted;
  final bool isActive;
  final bool isLast;

  @override
  Widget build(BuildContext context) {
    final color = isCompleted || isActive ? AppColors.primary : AppColors.border;

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 28,
            child: Column(
              children: [
                Container(
                  width: 20,
                  height: 20,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: isCompleted
                        ? AppColors.primary
                        : isActive
                        ? AppColors.primary.withValues(alpha: 0.15)
                        : AppColors.border,
                    border: Border.all(
                      color: color,
                      width: isActive && !isCompleted ? 2 : 0,
                    ),
                  ),
                  child: isCompleted
                      ? const Icon(Icons.check, size: 12, color: Colors.white)
                      : null,
                ),
                if (!isLast)
                  Expanded(
                    child: Container(
                      width: 2,
                      color: isCompleted ? AppColors.primary : AppColors.border,
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(bottom: isLast ? 0 : AppSpacing.lg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      color: isCompleted || isActive
                          ? AppColors.textPrimary
                          : AppColors.textMuted,
                    ),
                  ),
                  if (subtitle.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(subtitle, style: Theme.of(context).textTheme.bodySmall),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class PgInvoiceRow extends StatelessWidget {
  const PgInvoiceRow({
    super.key,
    required this.label,
    required this.value,
    this.emphasize = false,
    this.isTotal = false,
  });

  final String label;
  final String value;
  final bool emphasize;
  final bool isTotal;

  @override
  Widget build(BuildContext context) => Padding(
    padding: EdgeInsets.symmetric(vertical: isTotal ? 8 : 4),
    child: Row(
      children: [
        Expanded(
          child: Text(
            label,
            style: isTotal
                ? Theme.of(context).textTheme.titleMedium
                : Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight: emphasize ? FontWeight.w600 : FontWeight.w400,
                  ),
          ),
        ),
        Text(
          value,
          style: isTotal
              ? Theme.of(context).textTheme.titleLarge?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: AppColors.primary,
                )
              : Theme.of(context).textTheme.titleSmall?.copyWith(
                  fontWeight: emphasize ? FontWeight.w700 : FontWeight.w500,
                ),
        ),
      ],
    ),
  );
}

class PgDetailHeader extends StatelessWidget {
  const PgDetailHeader({
    super.key,
    required this.title,
    required this.subtitle,
    this.badgeLabel,
    this.badgeTone = PgStatusTone.neutral,
  });

  final String title;
  final String subtitle;
  final String? badgeLabel;
  final PgStatusTone badgeTone;

  @override
  Widget build(BuildContext context) => PgCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(title, style: Theme.of(context).textTheme.titleLarge),
            ),
            if (badgeLabel != null) PgStatusBadge(label: badgeLabel!, tone: badgeTone),
          ],
        ),
        const SizedBox(height: 4),
        Text(subtitle, style: Theme.of(context).textTheme.bodyMedium),
      ],
    ),
  );
}
