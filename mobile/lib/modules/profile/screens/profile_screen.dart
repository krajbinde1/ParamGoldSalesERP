import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/app_version_label.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    final employee = auth.session!.employee;
    final rows = <(String, String)>[
      ('Employee Code', employee.employeeCode),
      ('Full Name', employee.fullName),
      ('Mobile Number', employee.mobile),
      ('Email', employee.email ?? '—'),
      ('Department', employee.department),
      ('Designation', employee.designation),
      ('Reporting Manager', employee.reportingManager ?? '—'),
      ('Base Location', employee.baseLocation),
      ('Joining Date', employee.joiningDate ?? '—'),
      ('Active Status', employee.active ? 'Active' : 'Inactive'),
    ];

    return PgPageScaffold(
      auth: auth,
      title: 'My Profile',
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenPadding),
        children: [
          Center(
            child: Container(
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                border: Border.all(
                  color: AppColors.primary.withValues(alpha: 0.3),
                  width: 3,
                ),
              ),
              child: CircleAvatar(
                radius: 52,
                backgroundColor: AppColors.primary.withValues(alpha: 0.1),
                backgroundImage: employee.profilePhotoUrl == null
                    ? null
                    : NetworkImage(employee.profilePhotoUrl!),
                child: employee.profilePhotoUrl == null
                    ? Text(
                        employee.fullName.substring(0, 1).toUpperCase(),
                        style: const TextStyle(
                          fontSize: 36,
                          fontWeight: FontWeight.w700,
                          color: AppColors.primary,
                        ),
                      )
                    : null,
              ),
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
          Center(
            child: Text(
              employee.fullName,
              style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
          Center(
            child: Text(
              employee.designation,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                color: AppColors.textSecondary,
              ),
            ),
          ),
          const SizedBox(height: AppSpacing.lg),
          PgCard(
            padding: EdgeInsets.zero,
            child: Column(
              children: rows.map((row) {
                return ListTile(
                  title: Text(
                    row.$1,
                    style: Theme.of(context).textTheme.labelMedium?.copyWith(
                      color: AppColors.textSecondary,
                    ),
                  ),
                  subtitle: Text(
                    row.$2,
                    style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                );
              }).toList(),
            ),
          ),
          const SizedBox(height: AppSpacing.lg),
          OutlinedButton.icon(
            icon: const Icon(Icons.password_outlined),
            label: const Text('Change Password'),
            onPressed: () => context.push('/change-password'),
          ),
          const SizedBox(height: AppSpacing.sm),
          FilledButton.icon(
            style: FilledButton.styleFrom(
              backgroundColor: AppColors.error,
            ),
            icon: const Icon(Icons.logout_rounded),
            label: const Text('Logout'),
            onPressed: auth.loading ? null : () async => auth.logout(),
          ),
          const SizedBox(height: AppSpacing.lg),
          const AppVersionLabel(),
        ],
      ),
    );
  }
}
