import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../../core/design/app_colors.dart';
import '../../../../core/design/app_spacing.dart';
import '../../../../core/navigation/navigation_guard.dart';
import '../../../../core/widgets/role_shell_widgets.dart';
import '../../../auth/providers/auth_controller.dart';

/// Legacy Inventory hub — Production Supervisor Inventory tab now opens
/// [InventoryDashboardScreen] directly. This screen remains for deep links
/// and only exposes allowed operational routes (no Masters / Inward).
class InventoryHomeScreen extends StatelessWidget {
  const InventoryHomeScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    final p = auth.permissions;

    return Scaffold(
      appBar: RoleAppBar(
        title: 'Inventory & Manufacturing',
        auth: auth,
        showBack: true,
        onBack: () => smartBack(context),
      ),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenPadding),
        children: [
          if (p.canViewInventory) ...[
            ModuleTile(
              icon: const Icon(Icons.bar_chart_outlined),
              label: 'Inventory Dashboard',
              subtitle: 'Stock overview, production & low stock',
              onTap: () => context.push('/production/inventory'),
            ),
            const SizedBox(height: AppSpacing.md),
            const _SectionLabel('Operations'),
            const SizedBox(height: AppSpacing.sm),
            if (p.canViewStockReport)
              ModuleTile(
                icon: const Icon(Icons.document_scanner_outlined),
                label: 'Inventory Stock Report',
                subtitle: 'Available qty, stock value & ledger',
                onTap: () => context.push('/production/stock-report'),
              ),
            if (p.canCreateProduction) ...[
              const SizedBox(height: AppSpacing.sm),
              ModuleTile(
                icon: const Icon(Icons.add_circle_outline),
                label: 'New Production Entry',
                subtitle: 'Select existing masters / BOM only',
                onTap: () => context.push('/production/entry'),
              ),
            ],
            if (p.canViewProductionHistory || p.canViewInventory) ...[
              const SizedBox(height: AppSpacing.sm),
              ModuleTile(
                icon: const Icon(Icons.history),
                label: 'Production History',
                subtitle: 'Completed production batches',
                onTap: () => context.push('/production/history'),
              ),
            ],
            if (p.canViewStockReport || p.canViewInventory) ...[
              const SizedBox(height: AppSpacing.sm),
              ModuleTile(
                icon: const Icon(Icons.receipt_long_outlined),
                label: 'Stock Ledger / Ledger Reports',
                subtitle: 'Browsable ledger & PDF reports',
                onTap: () => context.push('/production/stock-ledger'),
              ),
            ],
            if (p.canAdjustStock) ...[
              const SizedBox(height: AppSpacing.sm),
              ModuleTile(
                icon: const Icon(Icons.tune_outlined),
                label: 'Stock Adjustment',
                subtitle: 'Requires stock_adjustment permission',
                onTap: () => context.push(
                  '/production/stock-ledger?txn=stock_adjustment',
                ),
              ),
            ],
          ],
        ],
      ),
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);
  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: Theme.of(context).textTheme.labelLarge?.copyWith(
            color: AppColors.textSecondary,
            fontWeight: FontWeight.w700,
            letterSpacing: 0.2,
          ),
    );
  }
}

/// Legacy route wrapper — same as Inventory home (no Masters / Inward).
class InventoryManufacturingHubScreen extends StatelessWidget {
  const InventoryManufacturingHubScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  Widget build(BuildContext context) => InventoryHomeScreen(auth: auth);
}

/// Material Masters group — not linked from PS Inventory tab.
/// Kept for deep-link compatibility; create/edit gated by permissions.
class MaterialMastersHubScreen extends StatelessWidget {
  const MaterialMastersHubScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'Material Masters',
        auth: auth,
        showBack: true,
        onBack: () => smartBack(context),
      ),
      body: const Center(
        child: Padding(
          padding: EdgeInsets.all(AppSpacing.screenPadding),
          child: Text(
            'Material Masters are managed in Admin web.\n'
            'Production Supervisor mobile no longer opens Masters.',
            textAlign: TextAlign.center,
          ),
        ),
      ),
    );
  }
}

/// Material Inward group — not linked from PS Inventory tab.
class MaterialInwardHubScreen extends StatelessWidget {
  const MaterialInwardHubScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'Material Inward',
        auth: auth,
        showBack: true,
        onBack: () => smartBack(context),
      ),
      body: const Center(
        child: Padding(
          padding: EdgeInsets.all(AppSpacing.screenPadding),
          child: Text(
            'Raw Material Inward is not available on Production Supervisor mobile.\n'
            'Use Admin web for inward posting.',
            textAlign: TextAlign.center,
          ),
        ),
      ),
    );
  }
}

/// Production hub — New Entry + History only.
class ProductionModuleHubScreen extends StatelessWidget {
  const ProductionModuleHubScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    final p = auth.permissions;
    return Scaffold(
      appBar: RoleAppBar(
        title: 'Production Batches',
        auth: auth,
        showBack: true,
        onBack: () => smartBack(context),
      ),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenPadding),
        children: [
          if (p.canCreateProduction)
            ModuleTile(
              icon: const Icon(Icons.add_circle_outline),
              label: 'New Production Entry',
              subtitle: 'Review materials then confirm & post',
              onTap: () => context.push('/production/entry'),
            ),
          if (p.canViewProductionHistory || p.canViewInventory) ...[
            const SizedBox(height: AppSpacing.sm),
            ModuleTile(
              icon: const Icon(Icons.history),
              label: 'Production History',
              subtitle: 'Batch list & details',
              onTap: () => context.push('/production/history'),
            ),
          ],
        ],
      ),
    );
  }
}
