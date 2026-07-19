import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

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

/// Runs [action] only when the widget is still mounted after an async gap.
Future<void> whenMounted(
  BuildContext context,
  Future<void> Function() action,
) async {
  if (!context.mounted) return;
  await action();
}
