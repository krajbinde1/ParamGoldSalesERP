import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../modules/auth/providers/auth_controller.dart';
import '../design/app_colors.dart';
import '../design/app_spacing.dart';
import 'design/pg_card.dart';
import 'design/pg_metric_card.dart';

/// Shared role shell app bar. Extends [AppBar] (does not wrap it) so Scaffold
/// lays out [actions] correctly — custom actions appear immediately before the
/// profile avatar.
class RoleAppBar extends AppBar {
  RoleAppBar({
    super.key,
    required String title,
    required AuthController auth,
    super.bottom,
    List<Widget>? actions,
    bool showBack = false,
    VoidCallback? onBack,
  }) : super(
          title: Text(title),
          automaticallyImplyLeading: !showBack,
          leading: showBack
              ? IconButton(
                  icon: const Icon(Icons.arrow_back_rounded),
                  onPressed: onBack,
                )
              : null,
          actionsIconTheme: const IconThemeData(
            color: AppColors.textPrimary,
            size: 24,
          ),
          iconTheme: const IconThemeData(
            color: AppColors.textPrimary,
            size: 24,
          ),
          actions: [
            ...?actions,
            _RoleNotificationsButton(auth: auth),
            _RoleProfileMenuButton(auth: auth),
          ],
        );
}

class _RoleNotificationsButton extends StatelessWidget {
  const _RoleNotificationsButton({required this.auth});

  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: 'Notifications',
      onPressed: () => context.push('/notifications'),
      icon: const Icon(Icons.notifications_none_rounded),
    );
  }
}

class _RoleProfileMenuButton extends StatelessWidget {
  const _RoleProfileMenuButton({required this.auth});

  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    final employee = auth.session?.employee;
    return PopupMenuButton<String>(
      tooltip: 'Account',
      onSelected: (value) async {
        switch (value) {
          case 'profile':
            context.push('/profile');
          case 'password':
            context.push('/change-password');
          case 'logout':
            await auth.logout();
        }
      },
      itemBuilder: (_) => const [
        PopupMenuItem(
          value: 'profile',
          child: ListTile(
            leading: Icon(Icons.person_outline),
            title: Text('My Profile'),
          ),
        ),
        PopupMenuItem(
          value: 'password',
          child: ListTile(
            leading: Icon(Icons.password_outlined),
            title: Text('Change Password'),
          ),
        ),
        PopupMenuDivider(),
        PopupMenuItem(
          value: 'logout',
          child: ListTile(
            leading: Icon(Icons.logout),
            title: Text('Logout'),
          ),
        ),
      ], // Icons above are const via the surrounding const list.
      child: Padding(
        padding: const EdgeInsets.only(right: 12),
        child: CircleAvatar(
          backgroundColor: AppColors.primary.withValues(alpha: 0.12),
          backgroundImage: employee?.profilePhotoUrl != null
              ? NetworkImage(employee!.profilePhotoUrl!)
              : null,
          child: employee?.profilePhotoUrl == null
              ? Text(
                  employee?.fullName.isNotEmpty == true
                      ? employee!.fullName.trim()[0].toUpperCase()
                      : '?',
                  style: const TextStyle(
                    color: AppColors.primary,
                    fontWeight: FontWeight.w700,
                  ),
                )
              : null,
        ),
      ),
    );
  }
}

class DashboardMetricCard extends StatelessWidget {
  const DashboardMetricCard({
    super.key,
    required this.label,
    required this.value,
    this.icon = const Icon(Icons.analytics_outlined),
    this.gradient = AppColors.tealGradient,
    this.onTap,
  });

  final String label;
  final String value;
  final Widget icon;
  final List<Color> gradient;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => SizedBox(
    width: 160,
    height: 120,
    child: PgMetricCard(
      title: label,
      value: value,
      icon: icon,
      gradient: gradient,
      onTap: onTap,
    ),
  );
}

class ModuleTile extends StatelessWidget {
  const ModuleTile({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
    this.subtitle,
    this.iconColor = AppColors.primary,
  });

  final Widget icon;
  final String label;
  final String? subtitle;
  final VoidCallback onTap;
  final Color iconColor;

  @override
  Widget build(BuildContext context) => PgCard(
    onTap: onTap,
    child: Row(
      children: [
        Container(
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            color: iconColor.withValues(alpha: 0.12),
            borderRadius: BorderRadius.circular(AppSpacing.radiusSm),
          ),
          child: IconTheme(
            data: IconThemeData(color: iconColor, size: 22),
            child: Center(child: icon),
          ),
        ),
        const SizedBox(width: AppSpacing.md),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
              if (subtitle != null) ...[
                const SizedBox(height: 2),
                Text(
                  subtitle!,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
                ),
              ],
            ],
          ),
        ),
        const Icon(Icons.chevron_right_rounded, color: AppColors.textMuted),
      ],
    ),
  );
}
