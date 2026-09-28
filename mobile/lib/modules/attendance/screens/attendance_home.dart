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
  String? _routeSyncSignature;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      refreshRouteTrackingStatus(ref);
      final asyncToday = ref.read(todayAttendanceProvider);
      if (asyncToday.isLoading && !asyncToday.hasValue) return;
      _syncRoute(asyncToday.asData?.value);
    });
  }

  void _syncRoute(Attendance? attendance) {
    final signature =
        '${attendance?.id}|${attendance?.runsLiveWorkingTimer}|${attendance?.punchOutCorrectionRequired}|${attendance?.punchOutCorrectionPending}|${attendance?.punchOut}|${attendance?.openAttendanceId}';
    if (_routeSyncSignature == signature) return;
    _routeSyncSignature = signature;
    if (attendance?.runsLiveWorkingTimer == true) {
      unawaited(() async {
        await RouteTrackingService.instance.recoverFromAttendance(attendance);
        if (mounted) await refreshRouteTrackingStatus(ref);
      }());
      return;
    }
    unawaited(() async {
      await RouteTrackingService.instance.closeStaleSession(
        attendanceId: attendance?.openAttendanceId ?? attendance?.id,
      );
      if (mounted) await refreshRouteTrackingStatus(ref);
    }());
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(todayAttendanceProvider);
    final routeStatus = ref.watch(routeTrackingStatusProvider);
    ref.listen(todayAttendanceProvider, (previous, next) {
      next.whenData(_syncRoute);
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
      body: SafeArea(
        top: false,
        child: RefreshIndicator(
        onRefresh: () async {
          await ref.read(todayAttendanceProvider.notifier).refresh();
          await refreshRouteTrackingStatus(ref);
        },
        child: ListView(
          padding: EdgeInsets.fromLTRB(
            AppSpacing.screenPadding,
            AppSpacing.screenPadding,
            AppSpacing.screenPadding,
            AppSpacing.screenPadding + MediaQuery.paddingOf(context).bottom,
          ),
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
                  if (a?.pendingCorrection != null) ...[
                    const SizedBox(height: AppSpacing.md),
                    _PendingBanner(
                      title: 'Correction submitted',
                      message:
                          'Requested punch out: ${a!.pendingCorrection!['requested_punch_out_time_label'] ?? '—'}\n'
                          'Reason: ${a.pendingCorrection!['reason_label'] ?? a.pendingCorrection!['reason'] ?? '—'}\n'
                          'Punch In stays blocked until a manager or admin approves this request.',
                    ),
                  ],
                  if (a?.canPunchOut == true && a?.id != null && a?.runsLiveWorkingTimer == true) ...[
                    const SizedBox(height: AppSpacing.md),
                    RouteSimulatorPanel(attendanceId: a!.id!),
                  ],
                  const SizedBox(height: AppSpacing.lg),
                  ..._actionButtons(context, a),
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
      ),
    );
  }

  List<Widget> _actionButtons(BuildContext context, Attendance? a) {
    final buttons = <Widget>[];

    void addButton(String label, IconData icon, VoidCallback? onPressed) {
      buttons.add(
        SizedBox(
          width: double.infinity,
          height: 56,
          child: FilledButton.icon(
            onPressed: onPressed,
            icon: Icon(icon),
            label: Text(label, textAlign: TextAlign.center),
          ),
        ),
      );
    }

    if (a?.canPunchOut == true) {
      addButton('PUNCH OUT', Icons.logout_rounded, () {
        context.push('/attendance/punch-out');
      });
    }

    if (a?.punchOutCorrectionPending == true) {
      addButton(
        'CORRECTION PENDING APPROVAL',
        Icons.hourglass_top_rounded,
        null,
      );
    } else if (a?.punchOutCorrectionRequired == true) {
      addButton(
        'COMPLETE PREVIOUS PUNCH OUT',
        Icons.rule_folder_outlined,
        () => context.push('/attendance/punch-out-correction'),
      );
    }

    final blocked =
        a?.canPunchOut == true ||
        a?.punchOutCorrectionRequired == true ||
        a?.punchOutCorrectionPending == true;
    if (!blocked && (a == null || a.canPunchIn)) {
      addButton('PUNCH IN', Icons.fingerprint_rounded, () {
        context.push('/attendance/punch-in');
      });
    }

    if (buttons.isEmpty) {
      addButton('ATTENDANCE COMPLETED', Icons.verified_rounded, null);
    }

    return [
      for (var i = 0; i < buttons.length; i++) ...[
        if (i > 0) const SizedBox(height: AppSpacing.sm),
        buttons[i],
      ],
    ];
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
