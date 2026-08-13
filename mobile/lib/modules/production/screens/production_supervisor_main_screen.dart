import 'package:flutter/material.dart';
import '../../auth/providers/auth_controller.dart';
import 'inventory/inventory_dashboard_screen.dart';
import 'production_dashboard_screen.dart';

/// Production Supervisor shell: Orders (default) | Inventory only.
/// Inventory tab opens Inventory Dashboard first (not Stock Report / Masters).
class ProductionSupervisorMainScreen extends StatefulWidget {
  const ProductionSupervisorMainScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<ProductionSupervisorMainScreen> createState() =>
      _ProductionSupervisorMainScreenState();
}

class _ProductionSupervisorMainScreenState
    extends State<ProductionSupervisorMainScreen> {
  int _tabIndex = 0;

  @override
  void initState() {
    super.initState();
    // TEMP DEBUG — actual PS shell entry (Orders is default tab).
    // ignore: avoid_print
    print(
      '[PS ApprovedOrders DEBUG] ProductionSupervisorMainScreen.initState '
      'tabIndex=$_tabIndex (0=Orders)',
    );
  }

  @override
  Widget build(BuildContext context) {
    // TEMP DEBUG
    // ignore: avoid_print
    print(
      '[PS ApprovedOrders DEBUG] ProductionSupervisorMainScreen.build '
      'file=production_supervisor_main_screen.dart tabIndex=$_tabIndex',
    );
    return Scaffold(
      body: IndexedStack(
        index: _tabIndex,
        children: [
          ProductionOrdersScreen(auth: widget.auth),
          InventoryDashboardScreen(auth: widget.auth),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tabIndex,
        onDestinationSelected: (index) {
          // ignore: avoid_print
          print(
            '[PS ApprovedOrders DEBUG] PS bottom nav selected index=$index '
            '(0=Orders, 1=Inventory)',
          );
          setState(() => _tabIndex = index);
        },
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.local_shipping_outlined),
            selectedIcon: Icon(Icons.local_shipping),
            label: 'Orders',
          ),
          NavigationDestination(
            icon: Icon(Icons.inventory_2_outlined),
            selectedIcon: Icon(Icons.inventory_2),
            label: 'Inventory',
          ),
        ],
      ),
    );
  }
}
