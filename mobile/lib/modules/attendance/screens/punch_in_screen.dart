import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../providers/attendance_provider.dart';
import '../route_tracking/route_tracking_permissions.dart';
import '../route_tracking/route_tracking_provider.dart';
import '../route_tracking/route_tracking_service.dart';

class PunchInScreen extends ConsumerStatefulWidget {
  const PunchInScreen({super.key});
  @override
  ConsumerState<PunchInScreen> createState() => _PunchInScreenState();
}

class _PunchInScreenState extends ConsumerState<PunchInScreen> {
  bool busy = false;
  Future<void> submit() async {
    if (busy) return;
    setState(() => busy = true);
    try {
      await ref.read(todayAttendanceProvider.notifier).punch('punch-in');
      await refreshRouteTrackingStatus(ref);
      if (!mounted) return;
      final trackingStatus = RouteTrackingService.instance.statusMessage;
      final trackingActive =
          RouteTrackingService.instance.uiStatus.isActive ||
          trackingStatus == 'Route Tracking Active';
      final guidance =
          RouteTrackingService.instance.uiStatus.permissionStatus == 'OK'
          ? ''
          : ' ${RouteTrackingPermissions.setupGuidance}';
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            trackingActive
                ? 'Punch in recorded successfully. Route Tracking Active.$guidance'
                : trackingStatus.isEmpty
                ? 'Punch in recorded successfully.'
                : 'Punch in recorded successfully. $trackingStatus',
          ),
        ),
      );
      context.pop();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('$e')));
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => PunchScreen(
    title: 'Punch In',
    icon: const Icon(Icons.fingerprint_rounded),
    message: 'Your current GPS location and a live selfie are required.',
    busy: busy,
    onPressed: submit,
  );
}

class PunchScreen extends StatelessWidget {
  const PunchScreen({
    super.key,
    required this.title,
    required this.icon,
    required this.message,
    required this.busy,
    required this.onPressed,
  });
  final String title, message;
  final Widget icon;
  final bool busy;
  final VoidCallback onPressed;
  @override
  Widget build(BuildContext context) => PgPageScaffold(
    title: title,
    showBack: true,
    body: SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.screenPadding),
        child: Column(
          children: [
            const Spacer(),
            IconTheme(
              data: IconThemeData(
                size: 100,
                color: Theme.of(context).colorScheme.primary,
              ),
              child: icon,
            ),
            const SizedBox(height: AppSpacing.lg),
            Text(title, style: Theme.of(context).textTheme.headlineMedium),
            const SizedBox(height: AppSpacing.sm),
            Text(message, textAlign: TextAlign.center),
            const Spacer(),
            SizedBox(
              width: double.infinity,
              height: 72,
              child: FilledButton.icon(
                onPressed: busy ? null : onPressed,
                icon: busy
                    ? const SizedBox.square(
                        dimension: 24,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : icon,
                label: Text(busy ? 'CAPTURING…' : title.toUpperCase()),
              ),
            ),
          ],
        ),
      ),
    ),
  );
}
