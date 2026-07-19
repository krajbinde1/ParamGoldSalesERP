import 'dart:io';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../models/attendance.dart';
import '../models/attendance_format.dart';

String timeText(DateTime? value) => AttendanceFormat.time(value);

class StatusCard extends StatelessWidget {
  const StatusCard({
    super.key,
    required this.attendance,
    this.routeTrackingStatus,
  });
  final Attendance? attendance;
  final String? routeTrackingStatus;

  @override
  Widget build(BuildContext context) {
    final a = attendance;
    final trackingStatus = routeTrackingStatus;
    final isPresent = a != null && !a.status.toLowerCase().contains('absent');

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
          if (trackingStatus != null &&
              trackingStatus.isNotEmpty &&
              a?.canPunchOut == true) ...[
            const SizedBox(height: AppSpacing.sm),
            Row(
              children: [
                Icon(
                  trackingStatus == 'Route Tracking Active'
                      ? Icons.route_rounded
                      : Icons.location_off_outlined,
                  size: 18,
                  color: trackingStatus == 'Route Tracking Active'
                      ? AppColors.approvedFg
                      : AppColors.textMuted,
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Text(
                    trackingStatus == 'Route Tracking Active'
                        ? 'Route Tracking Active'
                        : trackingStatus,
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                ),
              ],
            ),
          ],
          const SizedBox(height: AppSpacing.lg),
          Row(
            children: [
              Expanded(
                child: _Metric('Punch In', timeText(a?.punchIn), Icons.login_rounded),
              ),
              Expanded(
                child: _Metric(
                  'Punch Out',
                  timeText(a?.punchOut),
                  Icons.logout_rounded,
                ),
              ),
              Expanded(
                child: _Metric(
                  'Working',
                  a?.workingHours ?? '—',
                  Icons.schedule_rounded,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric(this.label, this.value, this.icon);
  final String label, value;
  final IconData icon;
  @override
  Widget build(BuildContext context) => Column(
    children: [
      Icon(icon, color: AppColors.primary),
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
