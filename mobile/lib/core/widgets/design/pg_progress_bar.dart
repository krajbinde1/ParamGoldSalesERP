import 'package:flutter/material.dart';
import '../../design/app_colors.dart';
import '../../design/app_spacing.dart';

class PgProgressBar extends StatelessWidget {
  const PgProgressBar({
    super.key,
    required this.label,
    required this.percentage,
    this.currentLabel,
    this.targetLabel,
    this.color = AppColors.primary,
  });

  final String label;
  final double percentage;
  final String? currentLabel;
  final String? targetLabel;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final value = (percentage / 100).clamp(0.0, 1.0);
    final percentText = percentage == percentage.roundToDouble()
        ? '${percentage.toInt()}%'
        : '${percentage.toStringAsFixed(1)}%';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                label,
                style: Theme.of(context).textTheme.titleSmall,
              ),
            ),
            Text(
              percentText,
              style: Theme.of(context).textTheme.labelLarge?.copyWith(
                color: color,
                fontWeight: FontWeight.w800,
              ),
            ),
          ],
        ),
        if (currentLabel != null || targetLabel != null) ...[
          const SizedBox(height: 4),
          Text(
            [
              if (currentLabel != null) currentLabel,
              if (targetLabel != null) 'of $targetLabel',
            ].join(' '),
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
        const SizedBox(height: AppSpacing.sm),
        ClipRRect(
          borderRadius: BorderRadius.circular(999),
          child: SizedBox(
            height: 10,
            child: Stack(
              children: [
                Container(color: const Color(0xFFE2E8F0)),
                FractionallySizedBox(
                  widthFactor: value,
                  child: Container(
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        colors: [color, color.withValues(alpha: 0.7)],
                      ),
                      borderRadius: BorderRadius.circular(999),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
