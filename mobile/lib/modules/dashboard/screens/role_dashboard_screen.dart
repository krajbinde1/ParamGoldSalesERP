import 'package:flutter/material.dart';
import '../../../core/auth/user_role.dart';
import '../../auth/providers/auth_controller.dart';
import 'dashboard_screen.dart';
import '../../director/screens/director_dashboard_screen.dart';
import '../../manager/screens/manager_dashboard_screen.dart';
import '../../production/screens/production_supervisor_main_screen.dart';

class RoleDashboardScreen extends StatelessWidget {
  const RoleDashboardScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    return switch (auth.userRole) {
      UserRole.manager => ManagerDashboardScreen(auth: auth),
      UserRole.productionSupervisor =>
        ProductionSupervisorMainScreen(auth: auth),
      UserRole.director => DirectorDashboardScreen(auth: auth),
      UserRole.employee => DashboardScreen(auth: auth),
    };
  }
}
