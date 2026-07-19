enum UserRole {
  employee('employee'),
  manager('manager'),
  productionSupervisor('production_supervisor'),
  director('director');

  const UserRole(this.value);
  final String value;

  static UserRole fromValue(String? value) {
    return UserRole.values.firstWhere(
      (role) => role.value == value,
      orElse: () => UserRole.employee,
    );
  }

  String get label => switch (this) {
    UserRole.employee => 'Employee',
    UserRole.manager => 'Manager',
    UserRole.productionSupervisor => 'Production Supervisor',
    UserRole.director => 'Director',
  };

  bool get isEmployee => this == UserRole.employee;
  bool get isManager => this == UserRole.manager;
  bool get isProductionSupervisor => this == UserRole.productionSupervisor;
  bool get isDirector => this == UserRole.director;

  bool canAccessEmployeeWorkflow() => isEmployee;

  bool canAccessManagerRoutes() => isManager;
  bool canAccessProductionRoutes() => isProductionSupervisor;
  bool canAccessDirectorRoutes() => isDirector;
}
