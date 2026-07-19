import 'package:flutter/material.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../models/attendance.dart';
import '../models/attendance_format.dart';
import '../widgets/attendance_widgets.dart';

class AttendanceDetail extends StatelessWidget {
  const AttendanceDetail({super.key, required this.attendance});
  final Attendance attendance;
  @override
  Widget build(BuildContext context) => PgPageScaffold(
    title: AttendanceFormat.date(attendance.date),
    showBack: true,
    body: ListView(
      padding: const EdgeInsets.all(AppSpacing.screenPadding),
      children: [
        StatusCard(attendance: attendance),
        const SizedBox(height: AppSpacing.md),
        PgCard(
          child: Column(
            children: [
              PgInvoiceRow(
                label: 'Punch In',
                value: timeText(attendance.punchIn),
              ),
              PgInvoiceRow(
                label: 'Punch Out',
                value: timeText(attendance.punchOut),
              ),
              PgInvoiceRow(
                label: 'Working Hours',
                value: attendance.workingHours ?? '—',
                emphasize: true,
              ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.md),
        PgCard(
          child: Column(
            children: [
              _Location(
                title: 'Punch In Location',
                address: attendance.inAddress,
                lat: attendance.inLatitude,
                lng: attendance.inLongitude,
              ),
              const Divider(height: AppSpacing.lg),
              _Location(
                title: 'Punch Out Location',
                address: attendance.outAddress,
                lat: attendance.outLatitude,
                lng: attendance.outLongitude,
              ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.md),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: AttendancePhoto(
                path: attendance.inPhoto,
                label: 'Punch In Selfie',
              ),
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: AttendancePhoto(
                path: attendance.outPhoto,
                label: 'Punch Out Selfie',
              ),
            ),
          ],
        ),
      ],
    ),
  );
}

class _Location extends StatelessWidget {
  const _Location({required this.title, this.address, this.lat, this.lng});
  final String title;
  final String? address;
  final double? lat, lng;
  @override
  Widget build(BuildContext context) => Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      const Icon(Icons.location_on_outlined),
      const SizedBox(width: AppSpacing.sm),
      Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: const TextStyle(fontWeight: FontWeight.bold)),
            const SizedBox(height: 4),
            Text(address ?? 'Not available'),
            if (lat != null && lng != null)
              Text(
                '${lat!.toStringAsFixed(6)}, ${lng!.toStringAsFixed(6)}',
                style: Theme.of(context).textTheme.bodySmall,
              ),
          ],
        ),
      ),
    ],
  );
}
