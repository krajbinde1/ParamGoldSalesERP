import 'package:flutter/material.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/updates/app_update_controller.dart';
import '../../../core/widgets/design/pg_brand.dart';

class ForceUpdateScreen extends StatefulWidget {
  const ForceUpdateScreen({super.key, required this.updates});

  final AppUpdateController updates;

  @override
  State<ForceUpdateScreen> createState() => _ForceUpdateScreenState();
}

class _ForceUpdateScreenState extends State<ForceUpdateScreen>
    with WidgetsBindingObserver {
  AppUpdateController get updates => widget.updates;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      updates.resumeAfterSettings();
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      child: ListenableBuilder(
        listenable: updates,
        builder: (context, _) {
          final downloading =
              updates.downloadState == AppUpdateDownloadState.downloading;
          final failed =
              updates.downloadState == AppUpdateDownloadState.failed;
          final percent = (updates.downloadProgress * 100).clamp(0, 100).round();

          return Scaffold(
            backgroundColor: AppColors.background,
            body: SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.xl),
                child: Column(
                  children: [
                    const Spacer(),
                    const PgBrandMark(size: 72),
                    const SizedBox(height: AppSpacing.lg),
                    Text(
                      'ParamGold Update Required',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                            fontWeight: FontWeight.w800,
                            color: AppColors.textPrimary,
                          ),
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    Text(
                      updates.message,
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                            color: AppColors.textSecondary,
                            fontWeight: FontWeight.w500,
                            height: 1.4,
                          ),
                    ),
                    const SizedBox(height: AppSpacing.xl),
                    _VersionRow(
                      label: 'Current Version',
                      value: updates.installedVersion.isEmpty
                          ? '-'
                          : updates.installedVersion,
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    _VersionRow(
                      label: 'New Version',
                      value: updates.latestVersion.isEmpty
                          ? '-'
                          : updates.latestVersion,
                    ),
                    if (updates.permissionHint != null) ...[
                      const SizedBox(height: AppSpacing.md),
                      Text(
                        updates.permissionHint!,
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              color: AppColors.warning,
                              fontWeight: FontWeight.w600,
                            ),
                      ),
                    ],
                    if (failed && updates.downloadError != null) ...[
                      const SizedBox(height: AppSpacing.md),
                      Text(
                        updates.downloadError!,
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              color: AppColors.error,
                              fontWeight: FontWeight.w600,
                            ),
                      ),
                    ],
                    if (downloading) ...[
                      const SizedBox(height: AppSpacing.xl),
                      Text(
                        'Downloading Update...',
                        style: Theme.of(context).textTheme.titleSmall?.copyWith(
                              fontWeight: FontWeight.w700,
                              color: AppColors.primary,
                            ),
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      ClipRRect(
                        borderRadius: BorderRadius.circular(999),
                        child: LinearProgressIndicator(
                          value: updates.downloadProgress > 0
                              ? updates.downloadProgress
                              : null,
                          minHeight: 8,
                          color: AppColors.primary,
                          backgroundColor: const Color(0xFFE2E8F0),
                        ),
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      Text(
                        '$percent%',
                        style: Theme.of(context).textTheme.titleMedium?.copyWith(
                              fontWeight: FontWeight.w800,
                              color: AppColors.primary,
                            ),
                      ),
                    ],
                    const Spacer(),
                    SizedBox(
                      width: double.infinity,
                      child: FilledButton(
                        onPressed: downloading ? null : updates.updateNow,
                        child: Text(
                          failed ? 'Retry Update' : 'Update Now',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

class _VersionRow extends StatelessWidget {
  const _VersionRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Text(
            label,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: AppColors.textSecondary,
                  fontWeight: FontWeight.w600,
                ),
          ),
        ),
        Text(
          value,
          style: Theme.of(context).textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.w800,
                color: AppColors.textPrimary,
              ),
        ),
      ],
    );
  }
}
