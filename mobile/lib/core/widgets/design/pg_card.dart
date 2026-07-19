import 'package:flutter/material.dart';
import '../../design/app_colors.dart';
import '../../design/app_spacing.dart';

/// Soft-shadow elevated card used across the app.
class PgCard extends StatelessWidget {
  const PgCard({
    super.key,
    required this.child,
    this.padding,
    this.onTap,
    this.margin,
    this.gradient,
  });

  final Widget child;
  final EdgeInsetsGeometry? padding;
  final VoidCallback? onTap;
  final EdgeInsetsGeometry? margin;
  final Gradient? gradient;

  @override
  Widget build(BuildContext context) {
    final content = Padding(
      padding: padding ?? const EdgeInsets.all(AppSpacing.cardPadding),
      child: child,
    );

    final decoration = BoxDecoration(
      color: gradient == null ? Theme.of(context).cardTheme.color : null,
      gradient: gradient,
      borderRadius: BorderRadius.circular(AppSpacing.radiusLg),
      border: Border.all(
        color: AppColors.border.withValues(alpha: 0.7),
      ),
      boxShadow: const [
        BoxShadow(
          color: AppColors.shadow,
          blurRadius: 16,
          offset: Offset(0, 4),
        ),
      ],
    );

    final card = Container(
      margin: margin,
      decoration: decoration,
      clipBehavior: Clip.antiAlias,
      child: onTap == null
          ? content
          : Material(
              color: Colors.transparent,
              child: InkWell(
                onTap: onTap,
                child: content,
              ),
            ),
    );

    return card;
  }
}
