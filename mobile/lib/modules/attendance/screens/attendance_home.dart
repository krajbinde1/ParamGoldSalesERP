import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../models/attendance_format.dart';
import '../providers/attendance_provider.dart';
import '../route_tracking/debug/route_simulator_panel.dart';
import '../route_tracking/route_tracking_provider.dart';
import '../route_tracking/route_tracking_service.dart';
import '../widgets/attendance_widgets.dart';

class AttendanceHome extends ConsumerStatefulWidget {
  const AttendanceHome({super.key});
  @override
  ConsumerState<AttendanceHome> createState() => _AttendanceHomeState();
}

class _AttendanceHomeState extends ConsumerState<AttendanceHome> {
  bool _didRecoverFromAttendance = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      refreshRouteTrackingStatus(ref);
    });
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(todayAttendanceProvider);
    final routeStatus = ref.watch(routeTrackingStatusProvider);
    ref.listen(todayAttendanceProvider, (previous, next) {
      next.whenData((a) {
        if (_didRecoverFromAttendance || a?.canPunchOut != true) return;
        _didRecoverFromAttendance = true;
        unawaited(() async {
          await RouteTrackingService.instance.recoverFromAttendance(a);
          if (mounted) {
            await refreshRouteTrackingStatus(ref);
          }
        }());
      });
    });
    return PgPageScaffold(
      title: 'Attendance',
      showBack: true,
      actions: [
        IconButton(
          onPressed: () => context.push('/attendance/history'),
          icon: const Icon(Icons.calendar_month_rounded),
        ),
      ],
      body: RefreshIndicator(
        onRefresh: () async {
          await ref.read(todayAttendanceProvider.notifier).refresh();
          await refreshRouteTrackingStatus(ref);
        },
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.screenPadding),
          children: [
            Text(
              AttendanceFormat.date(AttendanceFormat.istNow()),
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: AppSpacing.md),
            state.when(
              data: (a) => Column(
                children: [
                  StatusCard(attendance: a, routeTrackingStatus: routeStatus),
                  if (a?.canPunchOut == true && a?.id != null) ...[
                    const SizedBox(height: AppSpacing.md),
                    RouteSimulatorPanel(attendanceId: a!.id!),
                  ],
                  const SizedBox(height: AppSpacing.lg),
                  SizedBox(
                    width: double.infinity,
                    height: 64,
                    child: FilledButton.icon(
                      onPressed: a?.punchIn == null
                          ? () => context.push('/attendance/punch-in')
                          : a?.punchOut == null
                          ? () => context.push('/attendance/punch-out')
                          : null,
                      icon: Icon(
                        a?.punchIn == null
                            ? Icons.fingerprint_rounded
                            : Icons.logout_rounded,
                      ),
                      label: Text(
                        a?.punchIn == null
                            ? 'PUNCH IN'
                            : a?.punchOut == null
                            ? 'PUNCH OUT'
                            : 'ATTENDANCE COMPLETED',
                      ),
                    ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextButton.icon(
                    onPressed: () => context.push('/attendance/history'),
                    icon: const Icon(Icons.history_rounded),
                    label: const Text('View attendance history'),
                  ),
                  if (kDebugMode) ...[
                    const SizedBox(height: AppSpacing.lg),
                    TextButton.icon(
                      onPressed: () async {
                        try {
                          final message = await ref
                              .read(todayAttendanceProvider.notifier)
                              .resetTodayTest();
                          if (context.mounted) {
                            ScaffoldMessenger.of(
                              context,
                            ).showSnackBar(SnackBar(content: Text(message)));
                          }
                        } catch (error) {
                          if (context.mounted) {
                            ScaffoldMessenger.of(
                              context,
                            ).showSnackBar(SnackBar(content: Text('$error')));
                          }
                        }
                      },
                      icon: const Icon(Icons.bug_report_outlined),
                      label: const Text('TEST: Reset Today'),
                    ),
                  ],
                ],
              ),
              loading: () => const PgLoadingState(height: 200),
              error: (e, _) => PgErrorState(
                message: '$e',
                onRetry: () => ref.invalidate(todayAttendanceProvider),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
