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
          children: [
            _NavItem(
              tab: EmployeeNavTab.dashboard,
              label: 'Dashboard',
              selected: current == EmployeeNavTab.dashboard,
              onTap: onTap,
            ),
            _NavItem(
              tab: EmployeeNavTab.orders,
              label: 'Orders',
              selected: current == EmployeeNavTab.orders,
              onTap: onTap,
            ),
            _NavItem(
              tab: EmployeeNavTab.activities,
              label: 'Activities',
              selected: current == EmployeeNavTab.activities,
              onTap: onTap,
            ),
            _NavItem(
              tab: EmployeeNavTab.collections,
              label: 'Collections',
              selected: current == EmployeeNavTab.collections,
              onTap: onTap,
            ),
            _NavItem(
              tab: EmployeeNavTab.profile,
              label: 'Profile',
              selected: current == EmployeeNavTab.profile,
              onTap: onTap,
            ),
          ],
        ),
      ),
    );
  }
}

class _NavItem extends StatelessWidget {
  const _NavItem({
    required this.tab,
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final EmployeeNavTab tab;
  final String label;
  final bool selected;
  final ValueChanged<EmployeeNavTab> onTap;

  /// Const [Icon] per tab so Flutter 3.44 tree-shaking keeps each glyph.
  Widget _constIcon() => switch (tab) {
    EmployeeNavTab.dashboard => const Icon(Icons.dashboard_rounded),
    EmployeeNavTab.orders => const Icon(Icons.receipt_long_rounded),
    EmployeeNavTab.activities => const Icon(Icons.route_rounded),
    EmployeeNavTab.collections => const Icon(Icons.payments_rounded),
    EmployeeNavTab.profile => const Icon(Icons.person_rounded),
  };

  @override
  Widget build(BuildContext context) {
    final color = selected ? AppColors.primary : AppColors.textMuted;
    return Expanded(
      child: InkWell(
        borderRadius: BorderRadius.circular(20),
        onTap: () => onTap(tab),
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
                child: IconTheme(
                  data: IconThemeData(size: 22, color: color),
                  child: _constIcon(),
                ),
              ),
              const SizedBox(height: 2),
              Text(
                label,
                style: TextStyle(
                  fontSize: 10,
                  fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                  color: color,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
