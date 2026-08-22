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
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../../field_activities/models/field_activity_detail.dart';
import '../../field_activities/screens/field_activity_detail_screen.dart';
import '../api/director_api.dart';

class DirectorTodayFieldVisitsScreen extends StatefulWidget {
  const DirectorTodayFieldVisitsScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<DirectorTodayFieldVisitsScreen> createState() =>
      _DirectorTodayFieldVisitsScreenState();
}

class _DirectorTodayFieldVisitsScreenState
    extends State<DirectorTodayFieldVisitsScreen> {
  late Future<Map<String, dynamic>> _future;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.loadTodayFieldVisits();
  }

  Future<void> _reload() async {
    setState(() => _future = _api.loadTodayFieldVisits());
    await _future;
  }

  Future<void> _openVisit(int visitId) async {
    if (visitId <= 0) return;
    await context.push('/director/today-field-visits/$visitId');
    if (!mounted) return;
    await _reload();
  }

  String _formatTime(Object? value) {
    if (value == null) return '';
    final raw = value.toString().trim();
    if (raw.isEmpty || raw == '-') return '';
    final parsed = DateTime.tryParse(raw) ??
        DateTime.tryParse('1970-01-01 $raw');
    if (parsed == null) return raw;
    return DateFormat('hh:mm a').format(parsed);
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
          title: "Today's Field Visits",
          auth: widget.auth,
          showBack: true,
          onBack: () => smartBack(context),
        ),
        body: RefreshIndicator(
          color: AppColors.primary,
          onRefresh: _reload,
          child: FutureBuilder<Map<String, dynamic>>(
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
                      message: 'Unable to load today\'s field visits',
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

              final body = snapshot.data ?? const <String, dynamic>{};
              final employees = (body['employees'] as List?)
                      ?.whereType<Map>()
                      .map((item) => Map<String, dynamic>.from(item))
                      .toList() ??
                  const <Map<String, dynamic>>[];
              final totalVisits =
                  int.tryParse('${body['total_visits'] ?? 0}') ?? 0;
              final employeesVisited =
                  int.tryParse('${body['employees_visited'] ?? 0}') ?? 0;

              if (employees.isEmpty || totalVisits == 0) {
                return ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  children: [
                    SizedBox(height: MediaQuery.sizeOf(context).height * 0.25),
                    const PgEmptyState(
                      message: 'No field visits recorded today',
                    ),
                  ],
                );
              }

              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  PgCard(
                    margin: const EdgeInsets.only(bottom: AppSpacing.md),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Total Field Visits: $totalVisits',
                          style: Theme.of(context)
                              .textTheme
                              .titleSmall
                              ?.copyWith(fontWeight: FontWeight.w800),
                        ),
                        Text(
                          'Employees Visited: $employeesVisited',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ],
                    ),
                  ),
                  for (final employee in employees)
                    _EmployeeVisitsGroup(
                      employee: employee,
                      formatTime: _formatTime,
                      onOpenVisit: _openVisit,
                    ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

class _EmployeeVisitsGroup extends StatelessWidget {
  const _EmployeeVisitsGroup({
    required this.employee,
    required this.formatTime,
    required this.onOpenVisit,
  });

  final Map<String, dynamic> employee;
  final String Function(Object? value) formatTime;
  final Future<void> Function(int visitId) onOpenVisit;

  @override
  Widget build(BuildContext context) {
    final name = employee['employee_name']?.toString() ?? '-';
    final visitsCount =
        int.tryParse('${employee['visits_count'] ?? 0}') ?? 0;
    final visits = (employee['visits'] as List?)
            ?.whereType<Map>()
            .map((item) => Map<String, dynamic>.from(item))
            .toList() ??
        const <Map<String, dynamic>>[];

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            name,
            style: Theme.of(context)
                .textTheme
                .titleSmall
                ?.copyWith(fontWeight: FontWeight.w800),
          ),
          Text(
            '$visitsCount Field Visit${visitsCount == 1 ? '' : 's'} Today',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
          ),
          const SizedBox(height: 8),
          for (final visit in visits)
            _VisitCard(
              visit: visit,
              formatTime: formatTime,
              onTap: () =>
                  onOpenVisit(int.tryParse('${visit['id'] ?? 0}') ?? 0),
            ),
        ],
      ),
    );
  }
}

class _VisitCard extends StatelessWidget {
  const _VisitCard({
    required this.visit,
    required this.formatTime,
    required this.onTap,
  });

  final Map<String, dynamic> visit;
  final String Function(Object? value) formatTime;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final farmerName = visit['farmer_name']?.toString().trim() ?? '';
    final village = visit['village']?.toString().trim() ?? '';
    final taluka = visit['taluka']?.toString().trim() ?? '';
    final district = visit['district']?.toString().trim() ?? '';
    final crop = visit['crop_name']?.toString().trim() ?? '';
    final recommendation =
        visit['product_recommendation']?.toString().trim() ?? '';
    final mobile = visit['farmer_mobile']?.toString().trim() ?? '';
    final remark = visit['remark']?.toString().trim() ?? '';
    final time = formatTime(visit['activity_time']);
    final locationLine = [
      if (taluka.isNotEmpty) taluka,
      if (district.isNotEmpty) district,
    ].join(', ');

    return PgCard(
      onTap: onTap,
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (farmerName.isNotEmpty)
            Text(
              farmerName,
              style: Theme.of(context)
                  .textTheme
                  .titleSmall
                  ?.copyWith(fontWeight: FontWeight.w700),
            ),
          if (village.isNotEmpty)
            Text(village, style: Theme.of(context).textTheme.bodySmall),
          if (locationLine.isNotEmpty)
            Text(locationLine, style: Theme.of(context).textTheme.bodySmall),
          if (crop.isNotEmpty)
            Text(crop, style: Theme.of(context).textTheme.bodySmall),
          if (recommendation.isNotEmpty)
            Text(
              recommendation,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          if (mobile.isNotEmpty)
            Text(
              'Farmer Mobile: $mobile',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          if (time.isNotEmpty)
            Text(time, style: Theme.of(context).textTheme.bodySmall),
          if (remark.isNotEmpty)
            Text(remark, style: Theme.of(context).textTheme.bodySmall),
        ],
      ),
    );
  }
}

class DirectorFieldVisitDetailScreen extends StatelessWidget {
  const DirectorFieldVisitDetailScreen({
    super.key,
    required this.auth,
    required this.visitId,
  });

  final AuthController auth;
  final int visitId;

  @override
  Widget build(BuildContext context) {
    final api = DirectorApi(
      ApiClient(SessionStore(), onUnauthorized: auth.sessionExpired).dio,
    );

    return FieldActivityDetailScreen(
      activityId: visitId,
      auth: auth,
      title: 'Field Visit Details',
      dateLabel: 'Visit Date',
      timeLabel: 'Visit Time',
      loadActivity: () async {
        final data = await api.getFieldVisit(visitId);
        return FieldActivityDetail.fromJson(data);
      },
    );
  }
}
