import 'package:flutter/material.dart';

import '../../design/app_colors.dart';
import '../../design/app_spacing.dart';

/// ParamGold brand mark used on splash and welcome/login screens.
class PgBrandMark extends StatelessWidget {
  const PgBrandMark({
    super.key,
    this.size = 72,
    this.showShadow = true,
  });

  final double size;
  final bool showShadow;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: AppColors.tealGradient,
        ),
        borderRadius: BorderRadius.circular(size * 0.28),
        boxShadow: showShadow
            ? [
                BoxShadow(
                  color: AppColors.primary.withValues(alpha: 0.28),
                  blurRadius: 20,
                  offset: const Offset(0, 8),
                ),
              ]
            : null,
        border: Border.all(
          color: Colors.white.withValues(alpha: 0.22),
          width: 1.2,
        ),
      ),
      child: Icon(
        Icons.diamond_rounded,
        size: size * 0.48,
        color: Colors.white,
      ),
    );
  }
}

class PgBrandHeader extends StatelessWidget {
  const PgBrandHeader({
    super.key,
    this.markSize = 64,
    this.compact = false,
    this.light = false,
  });

  final double markSize;
  final bool compact;
  final bool light;

  @override
  Widget build(BuildContext context) {
    final titleColor = light ? Colors.white : AppColors.textPrimary;
    final subtitleColor = light
        ? Colors.white.withValues(alpha: 0.82)
        : AppColors.textSecondary;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        PgBrandMark(size: markSize),
        SizedBox(height: compact ? AppSpacing.sm : AppSpacing.md),
        Text(
          'PARAMGOLD',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w800,
                letterSpacing: 2.4,
                color: titleColor,
              ),
        ),
        const SizedBox(height: 2),
        Text(
          'Sales ERP',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.w600,
                letterSpacing: 1.2,
                color: subtitleColor,
              ),
        ),
      ],
    );
  }
}

/// Soft corporate backdrop for auth entry screens.
class PgAuthBackdrop extends StatelessWidget {
  const PgAuthBackdrop({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [
            Color(0xFF0B4F4A),
            Color(0xFF0F766E),
            Color(0xFFF1F5F9),
          ],
          stops: [0.0, 0.38, 0.72],
        ),
      ),
      child: Stack(
        children: [
          Positioned(
            top: -80,
            right: -60,
            child: _GlowOrb(
              size: 220,
              color: Colors.white.withValues(alpha: 0.08),
            ),
          ),
          Positioned(
            top: 120,
            left: -70,
            child: _GlowOrb(
              size: 180,
              color: const Color(0xFF14B8A6).withValues(alpha: 0.16),
            ),
          ),
          Positioned(
            bottom: 80,
            right: -40,
            child: _GlowOrb(
              size: 160,
              color: AppColors.primary.withValues(alpha: 0.08),
            ),
          ),
          child,
        ],
      ),
    );
  }
}

class _GlowOrb extends StatelessWidget {
  const _GlowOrb({required this.size, required this.color});

  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: color,
        ),
      ),
    );
  }
}
