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
      return role.isProductionSupervisor;
    }

    if (path.startsWith('/director')) {
      return role.isDirector;
    }

    return true;
  }
}
