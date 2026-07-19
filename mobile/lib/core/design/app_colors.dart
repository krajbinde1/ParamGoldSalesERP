import 'package:flutter/material.dart';

/// ParamGold ERP design tokens — Material 3 enterprise palette.
abstract final class AppColors {
  static const Color primary = Color(0xFF0F766E);
  static const Color secondary = Color(0xFF10B981);
  static const Color accent = Color(0xFFF59E0B);
  static const Color background = Color(0xFFF8FAFC);
  static const Color surface = Colors.white;
  static const Color success = Color(0xFF16A34A);
  static const Color warning = Color(0xFFF97316);
  static const Color error = Color(0xFFDC2626);
  static const Color info = Color(0xFF2563EB);

  static const Color textPrimary = Color(0xFF0F172A);
  static const Color textSecondary = Color(0xFF64748B);
  static const Color textMuted = Color(0xFF94A3B8);
  static const Color border = Color(0xFFE2E8F0);
  static const Color shadow = Color(0x1A0F172A);

  // Status badge tones
  static const Color pendingBg = Color(0xFFFFF7ED);
  static const Color pendingFg = Color(0xFFC2410C);
  static const Color approvedBg = Color(0xFFECFDF5);
  static const Color approvedFg = Color(0xFF047857);
  static const Color dispatchedBg = Color(0xFFEFF6FF);
  static const Color dispatchedFg = Color(0xFF1D4ED8);
  static const Color rejectedBg = Color(0xFFFEF2F2);
  static const Color rejectedFg = Color(0xFFB91C1C);
  static const Color paidBg = Color(0xFFECFDF5);
  static const Color paidFg = Color(0xFF047857);

  // Gradient pairs for metric cards
  static const List<Color> tealGradient = [Color(0xFF0F766E), Color(0xFF14B8A6)];
  static const List<Color> greenGradient = [Color(0xFF059669), Color(0xFF34D399)];
  static const List<Color> amberGradient = [Color(0xFFD97706), Color(0xFFFBBF24)];
  static const List<Color> blueGradient = [Color(0xFF2563EB), Color(0xFF60A5FA)];
  static const List<Color> violetGradient = [Color(0xFF7C3AED), Color(0xFFA78BFA)];
  static const List<Color> roseGradient = [Color(0xFFE11D48), Color(0xFFFB7185)];
  static const List<Color> indigoGradient = [Color(0xFF4F46E5), Color(0xFF818CF8)];
  static const List<Color> cyanGradient = [Color(0xFF0891B2), Color(0xFF22D3EE)];
}
