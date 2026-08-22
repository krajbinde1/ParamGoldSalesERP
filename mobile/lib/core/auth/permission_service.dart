import '../auth/user_role.dart';

/// Server-provided permission keys from login/me response.
class PermissionService {
  const PermissionService(this._permissions, this.role);

  final List<String> _permissions;
  final UserRole role;

  bool has(String permission) => _permissions.contains(permission);

  bool get canAccessEmployeeWorkflow =>
      has('attendance') || role.canAccessEmployeeWorkflow();

  bool get canApproveOrders => has('orders_approve');
  bool get canRejectOrders => has('orders_reject');
  bool get canDispatchOrders =>
      has('orders_dispatch') || role.isProductionSupervisor;
  bool get canApproveTaDa => has('ta_da_approve');
  bool get canRejectTaDa => has('ta_da_reject');
  bool get canViewManagerDashboard => has('manager_dashboard');
  bool get canViewProductionDashboard => has('production_dashboard');
  bool get canViewDirectorDashboard => has('director_dashboard');
  bool get canViewAllOrders =>
      has('orders_view_all') || has('orders_view_production');
  bool get canViewDealerLedger =>
      has('dealer_ledger_view') && !role.isProductionSupervisor;

  /// Inventory & Manufacturing module (Production Supervisor).
  bool get canViewInventory => has('inventory_view');
  bool get canAccessInventoryManufacturing => canViewInventory;
  bool get canViewActiveBom => has('bom_view_active');
  bool get canCreateProduction => has('production_create');
  bool get canCompleteProduction => has('production_complete');
  bool get canViewProductionHistory => has('production_history_view');
  bool get canViewShortageReport => has('shortage_report_view');
  bool get canViewStockReport =>
      has('stock_report_view') || has('inventory_view');
  bool get canCreateRawMaterialInward => has('raw_material_inward_create');
  bool get canCreatePackagingMaterialInward =>
      has('packaging_material_inward_create') ||
      has('raw_material_inward_create');
  bool get canViewProductionCosts => has('production_cost_view');

  /// Stock adjustment — Director/Admin only unless explicitly granted.
  bool get canAdjustStock => has('stock_adjustment');

  /// Director/Admin inventory master CRUD (matches Laravel canManageInventoryMasters).
  bool get canManageInventoryMasters =>
      has('inventory_full_access') || has('bom_manage');
}
