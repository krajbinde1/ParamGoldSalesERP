import '../auth/user_role.dart';

class RoutePermissions {
  const RoutePermissions._();

  static bool canAccessPath(String path, UserRole role) {
    if (path.startsWith('/profile') ||
        path.startsWith('/change-password') ||
        path.startsWith('/notifications') ||
        path.startsWith('/critical-approval-alert')) {
      return true;
    }

    if (path == '/dashboard' ||
        path == '/splash' ||
        path == '/login' ||
        path == '/update-required') {
      return true;
    }

    // Manager self-attendance reuses the employee attendance screens.
    if (path.startsWith('/attendance')) {
      return role.isEmployee || role.isManager;
    }

    final employeeOnlyPrefixes = [
      '/orders',
      '/collections',
      '/payment-follow-ups',
      '/credit-notes',
      '/field-activities',
      '/dealer-visits',
      '/ta-da-claims',
      '/planning',
      '/targets',
      '/dealer-applications',
    ];

    if (employeeOnlyPrefixes.any(path.startsWith)) {
      return role.canAccessEmployeeWorkflow();
    }

    if (path.startsWith('/dealers')) {
      return role.isEmployee || role.isManager || role.isDirector;
    }

    if (path.startsWith('/manager')) {
      return role.isManager;
    }

    if (path.startsWith('/production')) {
      if (role.isProductionSupervisor) return true;
      if (role.isDirector) return directorCanAccessProductionPath(path);
      return false;
    }

    if (path.startsWith('/director')) {
      return role.isDirector;
    }

    return true;
  }

  /// Director may open the same Inventory Stock read screens as Production Supervisor.
  /// Write/action flows stay Production Supervisor only.
  static bool directorCanAccessProductionPath(String path) {
    const blockedPrefixes = [
      '/production/orders',
      '/production/entry',
      '/production/company-transport',
      '/production/inwards',
      '/production/packaging-inwards',
      '/production/material-inward',
    ];
    if (blockedPrefixes.any(path.startsWith)) return false;

    if (RegExp(r'/(create|edit|new)(/|$)').hasMatch(path)) return false;

    const allowedPrefixes = [
      '/production/inventory',
      '/production/inventory-manufacturing',
      '/production/stock-report',
      '/production/stock-ledger',
      '/production/ledger',
      '/production/history',
      '/production/batches',
      '/production/raw-materials',
      '/production/packaging-materials',
      '/production/semi-finished',
      '/production/finished-goods',
      '/production/bom',
      '/production/shortages',
      '/production/material-masters',
      '/production/production-hub',
    ];

    return allowedPrefixes.any(
      (prefix) => path == prefix || path.startsWith('$prefix/'),
    );
  }
}
