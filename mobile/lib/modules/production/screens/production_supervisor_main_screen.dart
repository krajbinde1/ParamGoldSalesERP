import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';

/// Production Supervisor home: module hub (not a trapped Orders/Inventory shell).
class ProductionSupervisorMainScreen extends StatelessWidget {
  const ProductionSupervisorMainScreen({super.key, required this.auth});
  final AuthController auth;

  Future<void> _open(BuildContext context, String path) {
    return context.push(path);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'Dashboard', auth: auth),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenPadding),
        children: [
          ModuleTile(
            icon: const Icon(Icons.local_shipping_outlined),
            label: 'Orders',
            subtitle: 'Approved, send for bill, billed and dispatch',
            onTap: () => _open(context, '/production/orders'),
          ),
          const SizedBox(height: AppSpacing.md),
          ModuleTile(
            icon: const Icon(Icons.inventory_2_outlined),
            label: 'Inventory',
            subtitle: 'Stock overview, production and reports',
            onTap: () => _open(context, '/production/inventory'),
          ),
        ],
      ),
    );
  }
}
