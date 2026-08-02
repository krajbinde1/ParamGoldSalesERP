import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/auth/permission_service.dart';
import 'package:mobile/core/auth/user_role.dart';

void main() {
  group('PermissionService inventory gating', () {
    test('production supervisor with inventory_view can access module', () {
      final permissions = PermissionService(
        const [
          'production_dashboard',
          'inventory_view',
          'bom_view_active',
          'production_create',
          'production_history_view',
          'stock_report_view',
        ],
        UserRole.productionSupervisor,
      );

      expect(permissions.canAccessInventoryManufacturing, isTrue);
      expect(permissions.canViewActiveBom, isTrue);
      expect(permissions.canCreateProduction, isTrue);
      expect(permissions.canViewStockReport, isTrue);
      expect(permissions.canViewProductionHistory, isTrue);
      expect(permissions.canCreateRawMaterialInward, isFalse);
      expect(permissions.canAdjustStock, isFalse);
      expect(permissions.canViewProductionCosts, isFalse);
    });

    test('employee does not get inventory module from role alone', () {
      final permissions = PermissionService(
        const ['attendance', 'orders_create'],
        UserRole.employee,
      );

      expect(permissions.canAccessInventoryManufacturing, isFalse);
      expect(permissions.canCreateProduction, isFalse);
      expect(permissions.canViewStockReport, isFalse);
    });

    test('cost visibility requires production_cost_view permission', () {
      final permissions = PermissionService(
        const ['inventory_view', 'production_cost_view'],
        UserRole.productionSupervisor,
      );

      expect(permissions.canViewProductionCosts, isTrue);
      expect(permissions.canManageInventoryMasters, isFalse);
    });

    test('stock adjustment only when stock_adjustment is granted', () {
      final permissions = PermissionService(
        const ['inventory_view', 'stock_adjustment'],
        UserRole.productionSupervisor,
      );

      expect(permissions.canAdjustStock, isTrue);
    });

    test('director inventory_full_access can manage masters', () {
      final permissions = PermissionService(
        const ['inventory_full_access', 'bom_manage', 'stock_adjustment'],
        UserRole.director,
      );

      expect(permissions.canManageInventoryMasters, isTrue);
      expect(permissions.canAdjustStock, isTrue);
    });
  });
}
