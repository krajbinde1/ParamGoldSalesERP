import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../models/attendance.dart';
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
                  if (a?.previousPunchOutPending == true) ...[
                    _PendingBanner(
                      title: 'Previous Punch Out Pending',
                      message: a?.punchOutCorrectionPending == true
                          ? 'Your punch-out correction is waiting for manager/admin approval. New Punch In is blocked until it is resolved.'
                          : a?.punchOutCorrectionRequired == true
                          ? 'Punch in is more than 24 hours old. Request a punch-out correction. New Punch In is blocked until it is resolved.'
                          : 'Complete yesterday’s punch out before punching in today.',
                    ),
                    const SizedBox(height: AppSpacing.md),
                  ],
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
                      onPressed: _homeAction(context, a),
                      icon: Icon(_homeIcon(a)),
                      label: Text(_homeLabel(a)),
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

  VoidCallback? _homeAction(BuildContext context, Attendance? a) {
    if (a?.punchOutCorrectionPending == true) return null;
    if (a?.punchOutCorrectionRequired == true) {
      return () => context.push('/attendance/punch-out-correction');
    }
    if (a?.canPunchOut == true) {
      return () => context.push('/attendance/punch-out');
    }
    if (a?.canPunchIn == true) {
      return () => context.push('/attendance/punch-in');
    }
    return null;
  }

  IconData _homeIcon(Attendance? a) {
    if (a?.punchOutCorrectionRequired == true ||
        a?.punchOutCorrectionPending == true) {
      return Icons.rule_folder_outlined;
    }
    if (a?.canPunchOut == true) return Icons.logout_rounded;
    if (a?.canPunchIn == true) return Icons.fingerprint_rounded;
    return Icons.verified_rounded;
  }

  String _homeLabel(Attendance? a) {
    if (a?.punchOutCorrectionPending == true) {
      return 'CORRECTION PENDING APPROVAL';
    }
    if (a?.punchOutCorrectionRequired == true) {
      return 'REQUEST PUNCH OUT CORRECTION';
    }
    if (a?.canPunchOut == true) return 'PUNCH OUT';
    if (a?.canPunchIn == true) return 'PUNCH IN';
    return 'ATTENDANCE COMPLETED';
  }
}

class _PendingBanner extends StatelessWidget {
  const _PendingBanner({required this.title, required this.message});

  final String title;
  final String message;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.warning_amber_rounded, color: AppColors.warning),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 4),
                Text(message, style: Theme.of(context).textTheme.bodySmall),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
