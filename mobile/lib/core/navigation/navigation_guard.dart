import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:go_router/go_router.dart';

/// Role-agnostic home route — [RoleDashboardScreen] picks the correct UI.
const String kRoleHomePath = '/dashboard';

bool _navigationLocked = false;

bool _canNavigateNow() {
  final phase = SchedulerBinding.instance.schedulerPhase;
  return phase == SchedulerPhase.idle ||
      phase == SchedulerPhase.postFrameCallbacks;
}

void _unlockAfterFrame() {
  SchedulerBinding.instance.addPostFrameCallback((_) {
    _navigationLocked = false;
  });
}

/// Runs [action] once, never during build/layout/paint, and never twice in
/// the same frame. Prevents Viewport `_doingMountOrUpdate` crashes from
/// duplicate Back / pop-during-rebuild.
void runGuardedNavigation(BuildContext context, void Function() action) {
  if (!context.mounted || _navigationLocked) return;

  _navigationLocked = true;

  void run() {
    try {
      if (!context.mounted) return;
      action();
    } finally {
      _unlockAfterFrame();
    }
  }

  if (_canNavigateNow()) {
    run();
    return;
  }

  SchedulerBinding.instance.addPostFrameCallback((_) => run());
}

void _popIfPossible(BuildContext context, [Object? result]) {
  if (!context.mounted) return;
  if (context.canPop()) {
    context.pop(result);
    return;
  }
  final navigator = Navigator.maybeOf(context);
  if (navigator != null && navigator.canPop()) {
    navigator.pop(result);
  }
}

void _goFallback(BuildContext context, String fallback) {
  if (!context.mounted) return;
  final current = GoRouterState.of(context).uri.path;
  if (current == fallback) return;
  context.go(fallback);
}

/// Pops the current route only when the widget is still mounted and the
/// navigator stack can safely pop (dialog, sheet, or pushed page).
void safePop(BuildContext context, [Object? result]) {
  runGuardedNavigation(context, () => _popIfPossible(context, result));
}

/// Navigates only when the widget is still mounted.
void safeGo(BuildContext context, String location) {
  runGuardedNavigation(context, () {
    if (!context.mounted) return;
    context.go(location);
  });
}

/// AppBar / system back with the same behavior:
/// pop when possible, otherwise go to [fallback] (default role home).
void smartBack(
  BuildContext context, {
  String fallback = kRoleHomePath,
  Object? result,
}) {
  runGuardedNavigation(context, () {
    if (!context.mounted) return;
    if (context.canPop() || (Navigator.maybeOf(context)?.canPop() ?? false)) {
      _popIfPossible(context, result);
      return;
    }
    _goFallback(context, fallback);
  });
}

/// After a multi-step flow (e.g. New Order → Review), return to [location]
/// by popping when that route is already under the current stack so Dashboard
/// stays beneath Orders. Falls back to [context.go] only when needed.
void popToOrGo(BuildContext context, String location) {
  runGuardedNavigation(context, () {
    if (!context.mounted) return;

    final matches =
        GoRouter.of(context).routerDelegate.currentConfiguration.matches;
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
  });
}

/// Runs [action] only when the widget is still mounted after an async gap.
Future<void> whenMounted(
  BuildContext context,
  Future<void> Function() action,
) async {
  if (!context.mounted) return;
  await action();
}

/// Waits until the current frame (and any in-progress route pop) has finished
/// updating Viewports, then runs [action] if [context] is still mounted.
///
/// Use after `await context.push(...)` so a list/dashboard reload cannot
/// re-enter `Viewport.update` during back navigation.
Future<void> afterNavigation(
  BuildContext context,
  Future<void> Function() action,
) async {
  if (!context.mounted) return;
  await SchedulerBinding.instance.endOfFrame;
  if (!context.mounted) return;
  await action();
}

/// Android system back uses the same handler as the AppBar back button,
/// without letting the navigator auto-pop and then popping again.
class SafeBackScope extends StatelessWidget {
  const SafeBackScope({
    super.key,
    required this.child,
    this.onBack,
    this.fallback = kRoleHomePath,
  });

  final Widget child;
  final VoidCallback? onBack;
  final String fallback;

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        handleBack(context);
      },
      child: child,
    );
  }

  void handleBack(BuildContext context) {
    if (!context.mounted) return;
    if (onBack != null) {
      onBack!();
      return;
    }
    smartBack(context, fallback: fallback);
  }
}
