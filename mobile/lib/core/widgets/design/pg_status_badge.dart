import 'package:flutter/material.dart';
import '../../design/app_colors.dart';

enum PgStatusTone {
  pending,
  approved,
  dispatched,
  rejected,
  paid,
  info,
  neutral,
}

abstract final class PgStatusRules {
  static PgStatusTone orderTone(String status) {
    final normalized = status.toLowerCase().replaceAll(' ', '_');
    if (normalized.contains('pending') || normalized == 'draft') {
      return PgStatusTone.pending;
    }
    if (normalized.contains('approved')) return PgStatusTone.approved;
    if (normalized.contains('dispatch') || normalized.contains('deliver')) {
      return PgStatusTone.dispatched;
    }
    if (normalized.contains('reject') || normalized.contains('cancel')) {
      return PgStatusTone.rejected;
    }
    return PgStatusTone.neutral;
  }

  static PgStatusTone claimTone(String status) {
    final normalized = status.toLowerCase();
    if (normalized.contains('paid') || normalized.contains('approved')) {
      return PgStatusTone.paid;
    }
    if (normalized.contains('reject')) return PgStatusTone.rejected;
    if (normalized.contains('pending')) return PgStatusTone.pending;
    return PgStatusTone.neutral;
  }

  static (Color bg, Color fg) colors(PgStatusTone tone) => switch (tone) {
    PgStatusTone.pending => (AppColors.pendingBg, AppColors.pendingFg),
    PgStatusTone.approved => (AppColors.approvedBg, AppColors.approvedFg),
    PgStatusTone.dispatched => (AppColors.dispatchedBg, AppColors.dispatchedFg),
    PgStatusTone.rejected => (AppColors.rejectedBg, AppColors.rejectedFg),
    PgStatusTone.paid => (AppColors.paidBg, AppColors.paidFg),
    PgStatusTone.info => (const Color(0xFFEFF6FF), AppColors.info),
    PgStatusTone.neutral => (const Color(0xFFF1F5F9), AppColors.textSecondary),
  };
}

class PgStatusBadge extends StatelessWidget {
  const PgStatusBadge({
    super.key,
    required this.label,
    this.tone = PgStatusTone.neutral,
  });

  final String label;
  final PgStatusTone tone;

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = PgStatusRules.colors(tone);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: Theme.of(context).textTheme.labelSmall?.copyWith(
          color: fg,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}
