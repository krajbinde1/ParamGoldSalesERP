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
  bool get canDispatchOrders => has('orders_dispatch');
  bool get canApproveTaDa => has('ta_da_approve');
  bool get canRejectTaDa => has('ta_da_reject');
  bool get canViewManagerDashboard => has('manager_dashboard');
  bool get canViewProductionDashboard => has('production_dashboard');
  bool get canViewDirectorDashboard => has('director_dashboard');
  bool get canViewAllOrders => has('orders_view_all') || has('orders_view_production');
  bool get canViewEmployeePerformance => has('employee_performance_view_all');
}
