import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

/// Role-agnostic home route — [RoleDashboardScreen] picks the correct UI.
const String kRoleHomePath = '/dashboard';

/// Pops the current route only when the widget is still mounted and the
/// navigator stack can safely pop (dialog, sheet, or pushed page).
void safePop(BuildContext context, [Object? result]) {
  if (!context.mounted) return;
  final navigator = Navigator.of(context);
  if (navigator.canPop()) {
    navigator.pop(result);
  }
}

/// Navigates only when the widget is still mounted.
void safeGo(BuildContext context, String location) {
  if (!context.mounted) return;
  context.go(location);
}

/// AppBar / system back with the same behavior:
/// pop when possible, otherwise go to [fallback] (default role home).
void smartBack(BuildContext context, {String fallback = kRoleHomePath}) {
  if (!context.mounted) return;
  if (context.canPop()) {
    context.pop();
    return;
  }
  final current = GoRouterState.of(context).uri.path;
  if (current == fallback) return;
  context.go(fallback);
}

/// After a multi-step flow (e.g. New Order → Review), return to [location]
/// by popping when that route is already under the current stack so Dashboard
/// stays beneath Orders. Falls back to [context.go] only when needed.
void popToOrGo(BuildContext context, String location) {
  if (!context.mounted) return;

  final matches = GoRouter.of(context).routerDelegate.currentConfiguration.matches;
  final inStack = matches.any((match) => match.matchedLocation == location);

  if (inStack) {
    var guard = 0;
    while (context.mounted &&
        GoRouterState.of(context).matchedLocation != location &&
        context.canPop() &&
        guard < 20) {
      context.pop();
      guard++;
    }
    if (context.mounted &&
        GoRouterState.of(context).matchedLocation != location) {
      context.go(location);
    }
    return;
  }

  context.go(location);
}

/// Runs [action] only when the widget is still mounted after an async gap.
Future<void> whenMounted(
  BuildContext context,
  Future<void> Function() action,
) async {
  if (!context.mounted) return;
  await action();
}
