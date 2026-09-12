import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/widgets/prompt_dialog.dart';
import 'providers/auth_controller.dart';

Future<void> confirmAndLogout(
  BuildContext context,
  AuthController auth,
) async {
  final confirmed = await confirmAction(
    context,
    title: 'Logout',
    message: 'Are you sure you want to logout?',
  );
  if (!confirmed || !context.mounted) return;

  await auth.logout();
  if (!context.mounted) return;
  context.go('/login');
}
