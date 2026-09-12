import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import '../../../core/auth/user_role.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/app_version_label.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  Future<void> _choosePhotoSource() async {
    if (widget.auth.loading) return;
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: const Text('Camera'),
              onTap: () => Navigator.pop(context, ImageSource.camera),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Gallery'),
              onTap: () => Navigator.pop(context, ImageSource.gallery),
            ),
          ],
        ),
      ),
    );
    if (source == null || !mounted) return;

    final image = await ImagePicker().pickImage(
      source: source,
      imageQuality: 85,
      maxWidth: 1440,
    );
    if (image == null || !mounted) return;

    final success = await widget.auth.updateProfilePhoto(image.path);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          success
              ? 'Profile photo updated.'
              : (widget.auth.message ?? 'Unable to update profile photo.'),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: widget.auth,
      builder: (context, _) {
        final employee = widget.auth.session?.employee;
        if (employee == null) return const SizedBox.shrink();

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
        final showBack = widget.auth.userRole != UserRole.employee;
        final uploading = widget.auth.loading;
        final initial = employee.fullName.trim().isNotEmpty
            ? employee.fullName.trim()[0].toUpperCase()
            : '?';

        return PgPageScaffold(
          auth: widget.auth,
          title: 'My Profile',
          showBack: showBack,
          body: ListView(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            children: [
              Center(
                child: GestureDetector(
                  onTap: uploading ? null : _choosePhotoSource,
                  child: Stack(
                    alignment: Alignment.bottomRight,
                    children: [
                      Container(
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          border: Border.all(
                            color: AppColors.primary.withValues(alpha: 0.3),
                            width: 3,
                          ),
                        ),
                        child: CircleAvatar(
                          radius: 52,
                          backgroundColor: AppColors.primary.withValues(
                            alpha: 0.1,
                          ),
                          backgroundImage: employee.profilePhotoUrl == null
                              ? null
                              : NetworkImage(employee.profilePhotoUrl!),
                          child: uploading
                              ? const CircularProgressIndicator()
                              : employee.profilePhotoUrl == null
                              ? Text(
                                  initial,
                                  style: const TextStyle(
                                    fontSize: 36,
                                    fontWeight: FontWeight.w700,
                                    color: AppColors.primary,
                                  ),
                                )
                              : null,
                        ),
                      ),
                      Container(
                        width: 36,
                        height: 36,
                        decoration: BoxDecoration(
                          color: AppColors.primary,
                          shape: BoxShape.circle,
                          border: Border.all(color: Colors.white, width: 2),
                        ),
                        child: const Icon(
                          Icons.camera_alt_rounded,
                          size: 18,
                          color: Colors.white,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: AppSpacing.sm),
              Center(
                child: TextButton(
                  onPressed: uploading ? null : _choosePhotoSource,
                  child: const Text('Change photo'),
                ),
              ),
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
                        style: Theme.of(context).textTheme.labelMedium
                            ?.copyWith(color: AppColors.textSecondary),
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
                style: FilledButton.styleFrom(backgroundColor: AppColors.error),
                icon: const Icon(Icons.logout_rounded),
                label: const Text('Logout'),
                onPressed: widget.auth.loading
                    ? null
                    : () async => widget.auth.logout(),
              ),
              const SizedBox(height: AppSpacing.lg),
              const AppVersionLabel(),
            ],
          ),
        );
      },
    );
  }
}
