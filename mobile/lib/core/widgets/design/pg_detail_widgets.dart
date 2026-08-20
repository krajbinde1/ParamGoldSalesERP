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
    this.isRejected = false,
  });

  final String title;
  final String subtitle;
  final bool isCompleted;
  final bool isActive;
  final bool isLast;
  final bool isRejected;

  @override
  Widget build(BuildContext context) {
    final color = isRejected
        ? AppColors.error
        : (isCompleted || isActive ? AppColors.primary : AppColors.border);

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
                    color: isRejected
                        ? AppColors.error
                        : isCompleted
                        ? AppColors.primary
                        : isActive
                        ? AppColors.primary.withValues(alpha: 0.15)
                        : AppColors.border,
                    border: Border.all(
                      color: color,
                      width: isActive && !isCompleted && !isRejected ? 2 : 0,
                    ),
                  ),
                  child: isRejected
                      ? const Icon(Icons.close, size: 12, color: Colors.white)
                      : isCompleted
                      ? const Icon(Icons.check, size: 12, color: Colors.white)
                      : null,
                ),
                if (!isLast)
                  Expanded(
                    child: Container(
                      width: 2,
                      color: isCompleted && !isRejected
                          ? AppColors.primary
                          : AppColors.border,
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
                      color: isRejected
                          ? AppColors.error
                          : isCompleted || isActive
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
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          flex: 2,
          child: Text(
            label,
            softWrap: true,
            style: isTotal
                ? Theme.of(context).textTheme.titleMedium
                : Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight: emphasize ? FontWeight.w600 : FontWeight.w400,
                  ),
          ),
        ),
        const SizedBox(width: AppSpacing.sm),
        Expanded(
          flex: 3,
          child: Text(
            value,
            textAlign: TextAlign.right,
            softWrap: true,
            style: isTotal
                ? Theme.of(context).textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: AppColors.primary,
                  )
                : Theme.of(context).textTheme.titleSmall?.copyWith(
                    fontWeight: emphasize ? FontWeight.w700 : FontWeight.w500,
                  ),
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
              child: Text(
                title,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleLarge,
              ),
            ),
            if (badgeLabel != null) ...[
              const SizedBox(width: 8),
              FittedBox(
                fit: BoxFit.scaleDown,
                child: PgStatusBadge(label: badgeLabel!, tone: badgeTone),
              ),
            ],
          ],
        ),
        const SizedBox(height: 4),
        Text(
          subtitle,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: Theme.of(context).textTheme.bodyMedium,
        ),
      ],
    ),
  );
}
