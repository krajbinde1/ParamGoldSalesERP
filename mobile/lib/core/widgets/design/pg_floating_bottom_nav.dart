import 'package:flutter/material.dart';
import '../../design/app_colors.dart';
import '../../design/app_spacing.dart';

enum EmployeeNavTab { dashboard, orders, activities, collections, profile }

abstract final class EmployeeNavRoutes {
  static const dashboard = '/dashboard';
  static const orders = '/orders';
  static const activities = '/field-activities';
  static const collections = '/collections';
  static const profile = '/profile';

  static EmployeeNavTab? tabForPath(String path) {
    if (path == dashboard || path.startsWith('$dashboard/')) {
      return EmployeeNavTab.dashboard;
    }
    if (path == orders) return EmployeeNavTab.orders;
    if (path == activities) return EmployeeNavTab.activities;
    if (path == collections) return EmployeeNavTab.collections;
    if (path == profile) return EmployeeNavTab.profile;
    return null;
  }

  static String pathForTab(EmployeeNavTab tab) => switch (tab) {
    EmployeeNavTab.dashboard => dashboard,
    EmployeeNavTab.orders => orders,
    EmployeeNavTab.activities => activities,
    EmployeeNavTab.collections => collections,
    EmployeeNavTab.profile => profile,
  };
}

class PgFloatingBottomNav extends StatelessWidget {
  const PgFloatingBottomNav({
    super.key,
    required this.current,
    required this.onTap,
  });

  final EmployeeNavTab current;
  final ValueChanged<EmployeeNavTab> onTap;

  @override
  Widget build(BuildContext context) {
    final items = <(EmployeeNavTab, IconData, String)>[
      (EmployeeNavTab.dashboard, Icons.dashboard_rounded, 'Dashboard'),
      (EmployeeNavTab.orders, Icons.receipt_long_rounded, 'Orders'),
      (EmployeeNavTab.activities, Icons.route_rounded, 'Activities'),
      (EmployeeNavTab.collections, Icons.payments_rounded, 'Collections'),
      (EmployeeNavTab.profile, Icons.person_rounded, 'Profile'),
    ];

    return SafeArea(
      minimum: const EdgeInsets.fromLTRB(16, 0, 16, 12),
      child: Container(
        height: AppSpacing.bottomNavHeight,
        decoration: BoxDecoration(
          color: Theme.of(context).cardTheme.color,
          borderRadius: BorderRadius.circular(24),
          border: Border.all(color: AppColors.border.withValues(alpha: 0.8)),
          boxShadow: const [
            BoxShadow(
              color: AppColors.shadow,
              blurRadius: 24,
              offset: Offset(0, 8),
            ),
          ],
        ),
        child: Row(
          children: items.map((item) {
            final selected = item.$1 == current;
            return Expanded(
              child: InkWell(
                borderRadius: BorderRadius.circular(20),
                onTap: () => onTap(item.$1),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 220),
                  curve: Curves.easeOutCubic,
                  margin: const EdgeInsets.symmetric(horizontal: 4, vertical: 8),
                  decoration: BoxDecoration(
                    color: selected
                        ? AppColors.primary.withValues(alpha: 0.1)
                        : Colors.transparent,
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      AnimatedScale(
                        scale: selected ? 1.1 : 1,
                        duration: const Duration(milliseconds: 220),
                        child: Icon(
                          item.$2,
                          size: 22,
                          color: selected ? AppColors.primary : AppColors.textMuted,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        item.$3,
                        style: TextStyle(
                          fontSize: 10,
                          fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                          color: selected ? AppColors.primary : AppColors.textMuted,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            );
          }).toList(),
        ),
      ),
    );
  }
}
