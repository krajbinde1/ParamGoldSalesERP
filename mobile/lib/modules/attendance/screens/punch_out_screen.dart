import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
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
      await ref.read(todayAttendanceProvider.notifier).punch('punch-out');
      if (mounted) {
        refreshRouteTrackingStatus(ref);
        final trackingStatus = RouteTrackingService.instance.statusMessage;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              trackingStatus == 'Route tracking stopped'
                  ? 'Punch out recorded successfully. Route tracking stopped.'
                  : 'Punch out recorded successfully.',
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
