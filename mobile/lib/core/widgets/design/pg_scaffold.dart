import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../auth/user_role.dart';
import '../../design/app_spacing.dart';
import '../../../modules/auth/providers/auth_controller.dart';
import 'pg_floating_bottom_nav.dart';

/// Wraps employee screens with floating bottom navigation on main tabs.
class PgEmployeeShell extends StatelessWidget {
  const PgEmployeeShell({
    super.key,
    required this.auth,
    required this.child,
    this.floatingActionButton,
  });

  final AuthController auth;
  final Widget child;
  final Widget? floatingActionButton;

  @override
  Widget build(BuildContext context) {
    final role = UserRole.fromValue(auth.session?.user.role);
    final location = GoRouterState.of(context).matchedLocation;
    final tab = EmployeeNavRoutes.tabForPath(location);
    final showNav = role.canAccessEmployeeWorkflow() && tab != null;

    return Scaffold(
      body: child,
      floatingActionButton: floatingActionButton,
      bottomNavigationBar: showNav
          ? PgFloatingBottomNav(
              current: tab,
              onTap: (selected) {
                final target = EmployeeNavRoutes.pathForTab(selected);
                if (location != target) context.go(target);
              },
            )
          : null,
    );
  }
}

/// Standard page scaffold with optional app bar for detail/sub screens.
class PgPageScaffold extends StatelessWidget {
  const PgPageScaffold({
    super.key,
    required this.body,
    this.title,
    this.actions,
    this.auth,
    this.floatingActionButton,
    this.showBack = false,
    this.bottom,
  });

  final Widget body;
  final String? title;
  final List<Widget>? actions;
  final AuthController? auth;
  final Widget? floatingActionButton;
  final bool showBack;
  final PreferredSizeWidget? bottom;

  @override
  Widget build(BuildContext context) {
    final location = GoRouterState.of(context).matchedLocation;
    final isMainTab = EmployeeNavRoutes.tabForPath(location) != null;
    final useShell = auth != null && isMainTab && !showBack;

    if (useShell) {
      return PgEmployeeShell(
        auth: auth!,
        floatingActionButton: floatingActionButton,
        child: _buildBody(context, showAppBar: title != null),
      );
    }

    return Scaffold(
      appBar: title == null
          ? null
          : AppBar(
              title: Text(title!),
              actions: actions,
              bottom: bottom,
              leading: showBack
                  ? IconButton(
                      icon: const Icon(Icons.arrow_back_rounded),
                      onPressed: () => context.pop(),
                    )
                  : null,
            ),
      floatingActionButton: floatingActionButton,
      body: body,
    );
  }

  Widget _buildBody(BuildContext context, {required bool showAppBar}) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (showAppBar)
          Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.screenPadding,
              AppSpacing.md,
              AppSpacing.screenPadding,
              AppSpacing.sm,
            ),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    title!,
                    style: Theme.of(context).textTheme.headlineSmall,
                  ),
                ),
                if (actions != null) ...actions!,
              ],
            ),
          ),
        Expanded(child: body),
      ],
    );
  }
}
