import 'dart:io';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../models/attendance.dart';
import '../models/attendance_format.dart';
import '../route_tracking/models/route_point.dart';
import '../route_tracking/route_tracking_permissions.dart';

String timeText(DateTime? value) => AttendanceFormat.time(value);

class StatusCard extends StatelessWidget {
  const StatusCard({
    super.key,
    required this.attendance,
    this.routeTrackingStatus,
  });
  final Attendance? attendance;
  final RouteTrackingUiStatus? routeTrackingStatus;

  @override
  Widget build(BuildContext context) {
    final a = attendance;
    final tracking = routeTrackingStatus;
    final isPresent = a != null && !a.status.toLowerCase().contains('absent');
    final trackingActive = tracking?.isActive == true;
    final showTracking =
        tracking != null &&
        tracking.message.isNotEmpty &&
        (a?.canPunchOut == true || tracking.pendingSyncCount > 0);

    return PgCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                a == null ? Icons.event_busy_rounded : Icons.verified_rounded,
                color: isPresent ? AppColors.approvedFg : AppColors.rejectedFg,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Text(
                  a?.status ?? 'Not Punched In',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
              ),
              if (a?.isPendingSync == true)
                const PgStatusBadge(
                  label: 'Sync pending',
                  tone: PgStatusTone.pending,
                ),
            ],
          ),
          if (showTracking) ...[
            const SizedBox(height: AppSpacing.sm),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(
                  trackingActive
                      ? Icons.route_rounded
                      : Icons.location_off_outlined,
                  size: 18,
                  color: trackingActive
                      ? AppColors.approvedFg
                      : AppColors.textMuted,
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        trackingActive
                            ? 'Route Tracking Active'
                            : tracking.message,
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                      const SizedBox(height: 4),
                      Text(
                        _trackingDetails(tracking),
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: AppColors.textMuted,
                        ),
                      ),
                      if (!trackingActive ||
                          tracking.permissionStatus != 'OK') ...[
                        const SizedBox(height: 6),
                        Text(
                          RouteTrackingPermissions.setupGuidance,
                          style: Theme.of(context).textTheme.bodySmall
                              ?.copyWith(color: AppColors.textMuted),
                        ),
                      ],
                    ],
                  ),
                ),
              ],
            ),
          ],
          const SizedBox(height: AppSpacing.lg),
          Row(
            children: [
              Expanded(
                child: _Metric(
                  'Punch In',
                  timeText(a?.punchIn),
                  const Icon(Icons.login_rounded),
                ),
              ),
              Expanded(
                child: _Metric(
                  'Punch Out',
                  timeText(a?.punchOut),
                  const Icon(Icons.logout_rounded),
                ),
              ),
              Expanded(
                child: _Metric(
                  'Working',
                  a?.workingHours ?? '—',
                  const Icon(Icons.schedule_rounded),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  String _trackingDetails(RouteTrackingUiStatus tracking) {
    final last = tracking.lastLocationAt;
    String lastLabel = 'Last location: —';
    if (last != null && last.isNotEmpty) {
      final parsed = DateTime.tryParse(last);
      lastLabel = parsed != null
          ? 'Last location: ${AttendanceFormat.time(parsed)}'
          : 'Last location: $last';
    }
    return '$lastLabel · Pending sync: ${tracking.pendingSyncCount} · '
        'GPS: ${tracking.gpsStatus} · Permission: ${tracking.permissionStatus}';
  }
}

class _Metric extends StatelessWidget {
  const _Metric(this.label, this.value, this.icon);
  final String label, value;
  final Widget icon;
  @override
  Widget build(BuildContext context) => Column(
    children: [
      IconTheme(
        data: const IconThemeData(color: AppColors.primary),
        child: icon,
      ),
      const SizedBox(height: 6),
      Text(value, style: Theme.of(context).textTheme.titleMedium),
      Text(label, style: Theme.of(context).textTheme.bodySmall),
    ],
  );
}

class AttendancePhoto extends StatelessWidget {
  const AttendancePhoto({super.key, required this.path, required this.label});
  final String? path;
  final String label;
  @override
  Widget build(BuildContext context) {
    Widget child;
    if (path == null || path!.isEmpty) {
      child = const Center(
        child: Icon(Icons.no_photography_outlined, size: 40),
      );
    } else if (path!.startsWith('http')) {
      child = CachedNetworkImage(
        imageUrl: path!,
        fit: BoxFit.cover,
        errorWidget: (_, _, _) => const Icon(Icons.broken_image),
      );
    } else {
      child = Image.file(
        File(path!),
        fit: BoxFit.cover,
        errorBuilder: (_, _, _) => const Icon(Icons.broken_image),
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.titleSmall),
        const SizedBox(height: AppSpacing.sm),
        ClipRRect(
          borderRadius: BorderRadius.circular(AppSpacing.radiusLg),
          child: AspectRatio(aspectRatio: 1, child: child),
        ),
      ],
    );
  }
}
