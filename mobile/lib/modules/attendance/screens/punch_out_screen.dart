import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../models/attendance_format.dart';
import '../providers/attendance_provider.dart';
import '../route_tracking/route_tracking_provider.dart';
import '../route_tracking/route_tracking_service.dart';
import 'punch_in_screen.dart';

class PunchOutScreen extends ConsumerStatefulWidget {
  const PunchOutScreen({super.key});
  @override
  ConsumerState<PunchOutScreen> createState() => _PunchOutScreenState();
}

class _PunchOutScreenState extends ConsumerState<PunchOutScreen> {
  bool busy = false;
  Future<void> submit() async {
    if (busy) return;
    setState(() => busy = true);
    try {
      final attendance = await ref
          .read(todayAttendanceProvider.notifier)
          .punch('punch-out');
      if (mounted) {
        refreshRouteTrackingStatus(ref);
        final punchInTime = attendance.punchIn == null
            ? '—'
            : AttendanceFormat.time(attendance.punchIn);
        final punchOutTime = attendance.punchOut == null
            ? '—'
            : AttendanceFormat.time(attendance.punchOut);
        final working = attendance.workingHours ?? '—';
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              'Punch Out Successful\n'
              'Punch In: $punchInTime\n'
              'Punch Out: $punchOutTime\n'
              'Working Hours: $working\n'
              'Status: ${attendance.status}',
            ),
          ),
        );
        context.pop();
      }
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
    title: 'Punch Out',
    icon: const Icon(Icons.logout_rounded),
    message:
        'We will capture your location and selfie again, then calculate your working hours.',
    busy: busy,
    onPressed: submit,
  );
}
