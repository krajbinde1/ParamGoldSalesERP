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

  /// Inventory & Manufacturing module (Production Supervisor + Director view).
  bool get canViewInventory =>
      has('inventory_view') || has('inventory_full_access');
  bool get canAccessInventoryManufacturing => canViewInventory;
  bool get canViewActiveBom => has('bom_view_active');
  bool get canCreateProduction => has('production_create') && !role.isDirector;
  bool get canCompleteProduction =>
      has('production_complete') && !role.isDirector;
  bool get canViewProductionHistory => has('production_history_view');
  bool get canViewShortageReport => has('shortage_report_view');
  bool get canViewStockReport =>
      has('stock_report_view') || canViewInventory;
  bool get canCreateRawMaterialInward => has('raw_material_inward_create');
  bool get canCreatePackagingMaterialInward =>
      has('packaging_material_inward_create') ||
      has('raw_material_inward_create');
  bool get canViewProductionCosts => has('production_cost_view');
  bool get canViewCompanyTransport =>
      has('company_transport_view') || role.isProductionSupervisor;
  bool get canCreateCompanyTransportExpense =>
      has('company_transport_expense_create');

  /// Stock adjustment — Admin web / PS when granted. Director mobile is view-only.
  bool get canAdjustStock => has('stock_adjustment') && !role.isDirector;

  /// Inventory master CRUD on mobile. Director mobile is view-only.
  bool get canManageInventoryMasters =>
      !role.isDirector && (has('inventory_full_access') || has('bom_manage'));
}
