import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/director_api.dart';

class DirectorTeamAttendanceScreen extends StatefulWidget {
  const DirectorTeamAttendanceScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<DirectorTeamAttendanceScreen> createState() =>
      _DirectorTeamAttendanceScreenState();
}

class _DirectorTeamAttendanceScreenState
    extends State<DirectorTeamAttendanceScreen> {
  late Future<DirectorRouteTrackingListResult> _future;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  String get _today => DateFormat('yyyy-MM-dd').format(DateTime.now());

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<DirectorRouteTrackingListResult> _load() =>
      _api.listRouteTracking(date: _today);

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  String _formatTime(Object? value) {
    if (value == null) return '—';
    final parsed = DateTime.tryParse(value.toString());
    if (parsed == null) {
      final raw = value.toString().trim();
      return raw.isEmpty ? '—' : raw;
    }
    return DateFormat('hh:mm a').format(parsed.toLocal());
  }

  String _workingHours(Map<String, dynamic> row) {
    final hours = row['working_hours']?.toString().trim() ?? '';
    if (hours.isNotEmpty) return hours;
    final minutes = int.tryParse('${row['total_working_minutes'] ?? ''}');
    if (minutes == null) return '—';
    return '${minutes ~/ 60}h ${minutes % 60}m';
  }

  PgStatusTone _attendanceTone(String status) {
    final normalized = status.toLowerCase();
    if (normalized.contains('present')) return PgStatusTone.approved;
    if (normalized.contains('half')) return PgStatusTone.pending;
    if (normalized.contains('absent') || normalized.contains('not punched')) {
      return PgStatusTone.rejected;
    }
    if (normalized.contains('punched') || normalized.contains('active')) {
      return PgStatusTone.info;
    }
    return PgStatusTone.neutral;
  }

  Future<void> _openRouteTracking() async {
    await context.push('/director/route-tracking');
    if (!mounted) return;
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    final canPop = context.canPop();
    return PopScope(
      canPop: canPop,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        smartBack(context);
      },
      child: Scaffold(
        appBar: RoleAppBar(
          title: 'Team Attendance Today',
          auth: widget.auth,
          showBack: true,
          onBack: () => smartBack(context),
        ),
        body: RefreshIndicator(
          color: AppColors.primary,
          onRefresh: _reload,
          child: FutureBuilder<DirectorRouteTrackingListResult>(
            future: _future,
            builder: (context, snapshot) {
              if (snapshot.connectionState == ConnectionState.waiting &&
                  !snapshot.hasData) {
                return const PgLoadingState();
              }
              if (snapshot.hasError) {
                return ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(AppSpacing.screenPadding),
                  children: [
                    PgErrorState(
                      message: 'Unable to load team attendance',
                      onRetry: _reload,
                    ),
                    const SizedBox(height: 8),
                    Text(
                      errorMessage(snapshot.error),
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: AppColors.textSecondary,
                          ),
                    ),
                  ],
                );
              }

              final rows = snapshot.data?.rows ?? const [];
              final extra = rows.isEmpty ? 2 : 1;

              return ListView.builder(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                itemCount: rows.length + extra,
                itemBuilder: (context, index) {
                  if (index == 0) {
                    return Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: PgCard(
                        onTap: _openRouteTracking,
                        child: Row(
                          children: [
                            const Icon(
                              Icons.route_outlined,
                              color: AppColors.primary,
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Text(
                                'Route Tracking',
                                style: Theme.of(context)
                                    .textTheme
                                    .titleSmall
                                    ?.copyWith(fontWeight: FontWeight.w800),
                              ),
                            ),
                            const Icon(
                              Icons.chevron_right_rounded,
                              color: AppColors.textSecondary,
                            ),
                          ],
                        ),
                      ),
                    );
                  }

                  if (rows.isEmpty) {
                    return const Padding(
                      padding: EdgeInsets.only(top: 48),
                      child: PgEmptyState(
                        message: 'No attendance records for today',
                      ),
                    );
                  }

                  final row = rows[index - 1];
                  final punchStatus =
                      row['punch_in_status']?.toString() ??
                          ((row['punch_in_time'] == null)
                              ? 'Not Punched In'
                              : 'Punched In');
                  final attendanceStatus =
                      row['attendance_status_label']?.toString() ??
                          row['attendance_status']?.toString() ??
                          punchStatus;
                  final activeStatus = row['display_status']?.toString();
                  final code = row['employee_code']?.toString() ?? '';

                  return PgCard(
                    margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          row['employee_name']?.toString() ?? '-',
                          style: Theme.of(context)
                              .textTheme
                              .titleSmall
                              ?.copyWith(fontWeight: FontWeight.w800),
                        ),
                        if (code.isNotEmpty)
                          Text(
                            code,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        const SizedBox(height: 8),
                        Text(
                          'Punch In: ${_formatTime(row['punch_in_time'])}',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        Text(
                          'Punch Out: ${_formatTime(row['punch_out_time'])}',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        Text(
                          'Working Hours: ${_workingHours(row)}',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        const SizedBox(height: 8),
                        Wrap(
                          spacing: 8,
                          runSpacing: 6,
                          children: [
                            PgStatusBadge(
                              label: punchStatus,
                              tone: _attendanceTone(punchStatus),
                            ),
                            if (attendanceStatus != punchStatus)
                              PgStatusBadge(
                                label: attendanceStatus,
                                tone: _attendanceTone(attendanceStatus),
                              ),
                            if (activeStatus != null &&
                                activeStatus.isNotEmpty &&
                                activeStatus != attendanceStatus &&
                                activeStatus != punchStatus)
                              PgStatusBadge(
                                label: activeStatus,
                                tone: _attendanceTone(activeStatus),
                              ),
                          ],
                        ),
                      ],
                    ),
                  );
                },
              );
            },
          ),
        ),
      ),
    );
  }
}
