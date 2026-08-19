import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../../manager/screens/manager_team_attendance_screen.dart';
import '../api/director_api.dart';

typedef RouteTrackingListLoader = Future<DirectorRouteTrackingListResult>
    Function({required String date});

typedef RouteTrackingDetailLoader = Future<Map<String, dynamic>> Function(
  int attendanceId,
);

/// Shared view-only route tracking list (Director + Manager reuse).
class DirectorRouteTrackingScreen extends StatefulWidget {
  const DirectorRouteTrackingScreen({
    super.key,
    required this.auth,
    this.title = 'Route Tracking',
    this.detailPathPrefix = '/director/route-tracking',
    this.listLoader,
  });

  final AuthController auth;
  final String title;
  final String detailPathPrefix;
  final RouteTrackingListLoader? listLoader;

  @override
  State<DirectorRouteTrackingScreen> createState() =>
      _DirectorRouteTrackingScreenState();
}

class _DirectorRouteTrackingScreenState
    extends State<DirectorRouteTrackingScreen> {
  late Future<DirectorRouteTrackingListResult> _future;
  DateTime _date = DateTime.now();

  DirectorApi get _directorApi => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  String get _dateParam => DateFormat('yyyy-MM-dd').format(_date);

  Future<DirectorRouteTrackingListResult> _load() {
    final loader = widget.listLoader;
    if (loader != null) {
      return loader(date: _dateParam);
    }
    return _directorApi.listRouteTracking(date: _dateParam);
  }

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: DateTime(2020),
      lastDate: DateTime.now().add(const Duration(days: 1)),
    );
    if (picked == null) return;
    setState(() => _date = picked);
    await _reload();
  }

  Future<void> _setToday() async {
    setState(() => _date = DateTime.now());
    await _reload();
  }

  String _formatTime(Object? value) {
    if (value == null) return '—';
    final parsed = DateTime.tryParse(value.toString());
    if (parsed == null) return value.toString();
    return DateFormat('hh:mm a').format(parsed.toLocal());
  }

  String _distance(Map<String, dynamic> row) {
    final value = double.tryParse('${row['total_route_distance_km'] ?? ''}');
    if (value == null) return '—';
    return '${value.toStringAsFixed(1)} km';
  }

  String _duration(Map<String, dynamic> row) {
    final hours = row['working_hours']?.toString();
    if (hours != null && hours.trim().isNotEmpty) return hours;
    final minutes = int.tryParse('${row['total_working_minutes'] ?? ''}');
    if (minutes == null) return '—';
    return '${minutes ~/ 60}h ${minutes % 60}m';
  }

  Future<void> _openRoute(int attendanceId) async {
    await context.push('${widget.detailPathPrefix}/$attendanceId');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: widget.title,
        auth: widget.auth,
        actions: [
          TextButton(
            onPressed: _setToday,
            child: const Text('Today'),
          ),
          IconButton(
            tooltip: 'Custom Date',
            onPressed: _pickDate,
            icon: const Icon(Icons.calendar_today_outlined),
          ),
        ],
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
                    message: 'Unable to load route tracking',
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
            if (rows.isEmpty) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: [
                  SizedBox(height: MediaQuery.sizeOf(context).height * 0.25),
                  const PgEmptyState(
                    message: 'No route tracking data for this date',
                  ),
                ],
              );
            }

            return ListView.builder(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              itemCount: rows.length + 1,
              itemBuilder: (context, index) {
                if (index == 0) {
                  return Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Text(
                      DateFormat('EEE, d MMM yyyy').format(_date),
                      style: Theme.of(context).textTheme.titleSmall?.copyWith(
                            fontWeight: FontWeight.w800,
                          ),
                    ),
                  );
                }
                final row = rows[index - 1];
                final attendanceId = int.tryParse('${row['id'] ?? ''}') ?? 0;
                final hasRoute = row['has_route'] == true && attendanceId > 0;
                final status = row['display_status']?.toString() ?? '—';

                return PgCard(
                  margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              row['employee_name']?.toString() ?? '-',
                              style: Theme.of(context)
                                  .textTheme
                                  .titleSmall
                                  ?.copyWith(fontWeight: FontWeight.w800),
                            ),
                          ),
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 8,
                              vertical: 3,
                            ),
                            decoration: BoxDecoration(
                              color: AppColors.primary.withValues(alpha: 0.1),
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: Text(
                              row['role_label']?.toString() ??
                                  row['role']?.toString() ??
                                  '-',
                              style: const TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w700,
                                color: AppColors.primary,
                              ),
                            ),
                          ),
                        ],
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
                        'Distance: ${_distance(row)} · Duration: ${_duration(row)}',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      Text(
                        'Status: $status',
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                      ),
                      const SizedBox(height: 10),
                      Align(
                        alignment: Alignment.centerRight,
                        child: FilledButton.tonalIcon(
                          onPressed:
                              hasRoute ? () => _openRoute(attendanceId) : null,
                          icon: const Icon(Icons.route_outlined, size: 18),
                          label: Text(hasRoute ? 'View Route' : 'No Route'),
                        ),
                      ),
                    ],
                  ),
                );
              },
            );
          },
        ),
      ),
    );
  }
}

class DirectorRouteMapScreen extends StatelessWidget {
  const DirectorRouteMapScreen({
    super.key,
    required this.auth,
    required this.attendanceId,
    this.loadRoute,
  });

  final AuthController auth;
  final int attendanceId;
  final RouteTrackingDetailLoader? loadRoute;

  @override
  Widget build(BuildContext context) {
    final api = DirectorApi(
      ApiClient(SessionStore(), onUnauthorized: auth.sessionExpired).dio,
    );

    return ManagerTeamRouteMapScreen(
      auth: auth,
      attendanceId: attendanceId,
      loadRoute: loadRoute ?? api.getRouteTracking,
    );
  }
}
