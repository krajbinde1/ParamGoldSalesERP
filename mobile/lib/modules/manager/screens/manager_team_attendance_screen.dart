import 'dart:convert';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:webview_flutter/webview_flutter.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/manager_api.dart';

class ManagerTeamAttendanceScreen extends StatefulWidget {
  const ManagerTeamAttendanceScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<ManagerTeamAttendanceScreen> createState() =>
      _ManagerTeamAttendanceScreenState();
}

class _ManagerTeamAttendanceScreenState
    extends State<ManagerTeamAttendanceScreen> {
  late final ManagerApi _api;
  late Future<ManagerTeamAttendanceListResult> _future;
  final _searchController = TextEditingController();
  DateTime _date = DateTime.now();

  @override
  void initState() {
    super.initState();
    _api = ManagerApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    );
    _future = _load();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  String get _dateParam => DateFormat('yyyy-MM-dd').format(_date);

  Future<ManagerTeamAttendanceListResult> _load() => _api.listTeamAttendance(
        date: _dateParam,
        search: _searchController.text.trim(),
      );

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

  String _formatTime(Object? value) {
    if (value == null) return 'Not Punched In';
    final parsed = DateTime.tryParse(value.toString());
    if (parsed == null) return value.toString();
    return DateFormat('hh:mm a').format(parsed.toLocal());
  }

  String _formatPunchOut(Object? value, {required bool hasAttendance}) {
    if (!hasAttendance) return 'Not Punched In';
    if (value == null) return 'Not Punched Out';
    final parsed = DateTime.tryParse(value.toString());
    if (parsed == null) return value.toString();
    return DateFormat('hh:mm a').format(parsed.toLocal());
  }

  String _workingDuration(Map<String, dynamic> row) {
    final hours = row['working_hours']?.toString();
    if (hours != null && hours.trim().isNotEmpty) return hours;
    final minutes = int.tryParse('${row['total_working_minutes'] ?? ''}');
    if (minutes == null) return '-';
    return '${minutes ~/ 60}h ${minutes % 60}m';
  }

  String _distance(Map<String, dynamic> row) {
    final value = double.tryParse('${row['total_route_distance_km'] ?? ''}');
    if (value == null) return '-';
    return '${value.toStringAsFixed(1)} km';
  }

  PgStatusTone _statusTone(String status) {
    final value = status.toLowerCase();
    if (value.contains('completed') || value == 'present') {
      return PgStatusTone.approved;
    }
    if (value.contains('working')) return PgStatusTone.info;
    return PgStatusTone.pending;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'Team Attendance', auth: widget.auth),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<ManagerTeamAttendanceListResult>(
          future: _future,
          builder: (context, snapshot) {
            final result = snapshot.data;
            final rows = result?.rows ?? const <Map<String, dynamic>>[];

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                PgCard(
                  onTap: _pickDate,
                  child: Row(
                    children: [
                      const Icon(Icons.today_outlined),
                      const SizedBox(width: AppSpacing.sm),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Today / Selected Date',
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                            Text(
                              DateFormat('dd MMM yyyy').format(_date),
                              style: Theme.of(context)
                                  .textTheme
                                  .titleSmall
                                  ?.copyWith(fontWeight: FontWeight.w700),
                            ),
                          ],
                        ),
                      ),
                      const Icon(Icons.edit_calendar_outlined),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                TextField(
                  controller: _searchController,
                  decoration: InputDecoration(
                    labelText: 'Search employee',
                    border: const OutlineInputBorder(),
                    prefixIcon: const Icon(Icons.search),
                    suffixIcon: IconButton(
                      onPressed: _reload,
                      icon: const Icon(Icons.check),
                    ),
                  ),
                  textInputAction: TextInputAction.search,
                  onSubmitted: (_) => _reload(),
                ),
                const SizedBox(height: AppSpacing.md),
                if (snapshot.connectionState == ConnectionState.waiting &&
                    result == null)
                  const Padding(
                    padding: EdgeInsets.only(top: 80),
                    child: PgLoadingState(),
                  )
                else if (snapshot.hasError)
                  PgErrorState(
                    message: errorMessage(snapshot.error),
                    onRetry: _reload,
                  )
                else ...[
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      _SummaryChip(
                        label: 'Total Team',
                        value: '${result?.totalEmployees ?? 0}',
                      ),
                      _SummaryChip(
                        label: 'Punched In',
                        value: '${result?.punchedIn ?? 0}',
                      ),
                      _SummaryChip(
                        label: 'Punched Out',
                        value: '${result?.punchedOut ?? 0}',
                      ),
                      _SummaryChip(
                        label: 'Not Punched In',
                        value: '${result?.notPunchedIn ?? 0}',
                      ),
                    ],
                  ),
                  const SizedBox(height: AppSpacing.md),
                  if (rows.isEmpty)
                    const PgEmptyState(
                      message: 'No attendance recorded for this date.',
                      icon: Icon(Icons.groups_outlined),
                    )
                  else
                    ...rows.map((row) {
                      final status = row['display_status']?.toString() ??
                          row['attendance_status']?.toString() ??
                          'Not Punched In';
                      final hasAttendance = row['has_attendance'] == true;
                      final employeeId =
                          int.tryParse('${row['employee_id'] ?? 0}') ?? 0;

                      return PgCard(
                        onTap: employeeId <= 0
                            ? null
                            : () => context.push(
                                  '/manager/team-attendance/employees/$employeeId'
                                  '?date=$_dateParam',
                                ),
                        margin:
                            const EdgeInsets.only(bottom: AppSpacing.sm),
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
                                        ?.copyWith(
                                          fontWeight: FontWeight.w700,
                                        ),
                                  ),
                                ),
                                PgStatusBadge(
                                  label: status,
                                  tone: _statusTone(status),
                                ),
                              ],
                            ),
                            const SizedBox(height: 4),
                            Text(
                              row['employee_code']?.toString() ?? '-',
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                            const SizedBox(height: 8),
                            Text(
                              'Punch In: ${_formatTime(hasAttendance ? row['punch_in_time'] : null)}',
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                            Text(
                              'Punch Out: ${_formatPunchOut(row['punch_out_time'], hasAttendance: hasAttendance)}',
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                            if (hasAttendance) ...[
                              Text(
                                'Working Duration: ${_workingDuration(row)}',
                                style: Theme.of(context).textTheme.bodySmall,
                              ),
                              Text(
                                'Distance: ${_distance(row)}',
                                style: Theme.of(context).textTheme.bodySmall,
                              ),
                            ],
                          ],
                        ),
                      );
                    }),
                ],
              ],
            );
          },
        ),
      ),
    );
  }
}

class _SummaryChip extends StatelessWidget {
  const _SummaryChip({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: (MediaQuery.sizeOf(context).width - 48) / 2,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: Theme.of(context).dividerColor),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: Theme.of(context).textTheme.bodySmall),
          const SizedBox(height: 4),
          Text(
            value,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
          ),
        ],
      ),
    );
  }
}

class ManagerEmployeeAttendanceScreen extends StatefulWidget {
  const ManagerEmployeeAttendanceScreen({
    super.key,
    required this.auth,
    required this.employeeId,
    this.initialDate,
  });

  final AuthController auth;
  final int employeeId;
  final String? initialDate;

  @override
  State<ManagerEmployeeAttendanceScreen> createState() =>
      _ManagerEmployeeAttendanceScreenState();
}

class _ManagerEmployeeAttendanceScreenState
    extends State<ManagerEmployeeAttendanceScreen> {
  late Future<ManagerEmployeeAttendanceHistoryResult> _future;
  late DateTime _month;
  DateTime? _selectedDate;

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    final initial = DateTime.tryParse(widget.initialDate ?? '') ?? DateTime.now();
    _selectedDate = DateTime(initial.year, initial.month, initial.day);
    _month = DateTime(initial.year, initial.month);
    _future = _load();
  }

  String get _monthParam => DateFormat('yyyy-MM').format(_month);

  Future<ManagerEmployeeAttendanceHistoryResult> _load() =>
      _api.getEmployeeAttendanceHistory(
        widget.employeeId,
        month: _monthParam,
      );

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _pickMonth() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _selectedDate ?? _month,
      firstDate: DateTime(2020),
      lastDate: DateTime.now().add(const Duration(days: 1)),
      helpText: 'Select date / month',
    );
    if (picked == null) return;
    setState(() {
      _selectedDate = DateTime(picked.year, picked.month, picked.day);
      _month = DateTime(picked.year, picked.month);
    });
    await _reload();
  }

  String _formatTime(Object? value, {String empty = '-'}) {
    if (value == null) return empty;
    final parsed = DateTime.tryParse(value.toString());
    if (parsed == null) return value.toString();
    return DateFormat('hh:mm a').format(parsed.toLocal());
  }

  String _duration(Map<String, dynamic> row) {
    final hours = row['working_hours']?.toString();
    if (hours != null && hours.trim().isNotEmpty) return hours;
    final minutes = int.tryParse('${row['total_working_minutes'] ?? ''}');
    if (minutes == null) return '-';
    return '${minutes ~/ 60}h ${minutes % 60}m';
  }

  String _distance(Map<String, dynamic> row) {
    final value = double.tryParse('${row['total_route_distance_km'] ?? ''}');
    if (value == null) return '-';
    return '${value.toStringAsFixed(1)} km';
  }

  Map<String, dynamic>? _selectedRow(List<Map<String, dynamic>> rows) {
    if (_selectedDate == null) return rows.isEmpty ? null : rows.first;
    final key = DateFormat('yyyy-MM-dd').format(_selectedDate!);
    for (final row in rows) {
      if (row['attendance_date']?.toString() == key) return row;
    }
    return null;
  }

  Future<void> _openAttendanceDetail(int attendanceId) async {
    await context.push('/manager/team-attendance/$attendanceId');
  }

  Future<void> _openRoute(int attendanceId) async {
    await context.push('/manager/team-attendance/$attendanceId/route');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'Employee Attendance',
        auth: widget.auth,
        actions: [
          IconButton(
            tooltip: 'Select date',
            onPressed: _pickMonth,
            icon: const Icon(Icons.calendar_month_outlined),
          ),
        ],
      ),
      body: FutureBuilder<ManagerEmployeeAttendanceHistoryResult>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting &&
              !snapshot.hasData) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(
              message: errorMessage(snapshot.error),
              onRetry: _reload,
            );
          }

          final result = snapshot.data!;
          final employee = result.employee;
          final selected = _selectedRow(result.rows);
          final selectedKey = _selectedDate == null
              ? null
              : DateFormat('yyyy-MM-dd').format(_selectedDate!);

          return RefreshIndicator(
            onRefresh: _reload,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                PgDetailHeader(
                  title: employee['full_name']?.toString() ?? '-',
                  subtitle: employee['employee_code']?.toString() ?? '-',
                  badgeLabel: selected?['display_status']?.toString() ??
                      'No attendance',
                  badgeTone: PgStatusTone.pending,
                ),
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  onTap: _pickMonth,
                  child: PgInvoiceRow(
                    label: 'Selected Date',
                    value: _selectedDate == null
                        ? DateFormat('MMM yyyy').format(_month)
                        : DateFormat('dd MMM yyyy').format(_selectedDate!),
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                if (selected == null)
                  const PgEmptyState(
                    message: 'No attendance recorded for this date.',
                    icon: Icon(Icons.event_busy_outlined),
                  )
                else
                  PgCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Attendance Summary',
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        const SizedBox(height: AppSpacing.sm),
                        PgInvoiceRow(
                          label: 'Punch In',
                          value: _formatTime(selected['punch_in_time']),
                        ),
                        PgInvoiceRow(
                          label: 'Punch Out',
                          value: _formatTime(
                            selected['punch_out_time'],
                            empty: 'Not Punched Out',
                          ),
                        ),
                        PgInvoiceRow(
                          label: 'Working Duration',
                          value: _duration(selected),
                        ),
                        PgInvoiceRow(
                          label: 'Route Distance',
                          value: _distance(selected),
                        ),
                        const SizedBox(height: AppSpacing.sm),
                        OutlinedButton(
                          onPressed: () {
                            final id =
                                int.tryParse('${selected['id'] ?? 0}') ?? 0;
                            if (id > 0) _openAttendanceDetail(id);
                          },
                          child: const Text('Open Full Attendance Detail'),
                        ),
                      ],
                    ),
                  ),
                const SizedBox(height: AppSpacing.lg),
                Text(
                  'Route History',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: AppSpacing.sm),
                if (result.rows.isEmpty)
                  const PgEmptyState(
                    message: 'No attendance history for this month.',
                    icon: Icon(Icons.history_outlined),
                  )
                else
                  ...result.rows.map((row) {
                    final date = row['attendance_date']?.toString() ?? '-';
                    final attendanceId =
                        int.tryParse('${row['id'] ?? 0}') ?? 0;
                    final isSelected = date == selectedKey;
                    return PgCard(
                      onTap: () {
                        final parsed = DateTime.tryParse(date);
                        if (parsed != null) {
                          setState(() => _selectedDate = parsed);
                        }
                      },
                      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  DateFormat('dd MMM yyyy').format(
                                    DateTime.tryParse(date) ?? DateTime.now(),
                                  ),
                                  style: Theme.of(context)
                                      .textTheme
                                      .titleSmall
                                      ?.copyWith(fontWeight: FontWeight.w700),
                                ),
                              ),
                              if (isSelected)
                                const PgStatusBadge(
                                  label: 'Selected',
                                  tone: PgStatusTone.info,
                                ),
                            ],
                          ),
                          const SizedBox(height: 6),
                          Text(
                            'Punch In: ${_formatTime(row['punch_in_time'])}',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                          Text(
                            'Punch Out: ${_formatTime(row['punch_out_time'], empty: 'Not Punched Out')}',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                          Text(
                            'Duration: ${_duration(row)}',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                          Text(
                            'Distance: ${_distance(row)}',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                          const SizedBox(height: AppSpacing.sm),
                          Align(
                            alignment: Alignment.centerRight,
                            child: FilledButton.tonalIcon(
                              onPressed: attendanceId <= 0
                                  ? null
                                  : () => _openRoute(attendanceId),
                              icon: const Icon(Icons.map_outlined),
                              label: const Text('View Route'),
                            ),
                          ),
                        ],
                      ),
                    );
                  }),
                const SizedBox(height: AppSpacing.xl),
              ],
            ),
          );
        },
      ),
    );
  }
}

class ManagerTeamAttendanceDetailScreen extends StatefulWidget {
  const ManagerTeamAttendanceDetailScreen({
    super.key,
    required this.auth,
    required this.attendanceId,
  });

  final AuthController auth;
  final int attendanceId;

  @override
  State<ManagerTeamAttendanceDetailScreen> createState() =>
      _ManagerTeamAttendanceDetailScreenState();
}

class _ManagerTeamAttendanceDetailScreenState
    extends State<ManagerTeamAttendanceDetailScreen> {
  late Future<Map<String, dynamic>> _future;

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.getTeamAttendance(widget.attendanceId);
  }

  Future<void> _reload() async {
    setState(() => _future = _api.getTeamAttendance(widget.attendanceId));
    await _future;
  }

  String _formatTime(Object? value, {String empty = '-'}) {
    if (value == null) return empty;
    final parsed = DateTime.tryParse(value.toString());
    if (parsed == null) return value.toString();
    return DateFormat('hh:mm a').format(parsed.toLocal());
  }

  Map<String, dynamic> _asMap(Object? value) {
    if (value is Map) return Map<String, dynamic>.from(value);
    return const {};
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'Employee Attendance', auth: widget.auth),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(
              message: errorMessage(snapshot.error),
              onRetry: _reload,
            );
          }

          final data = snapshot.data!;
          final employee = _asMap(data['employee']);
          final attendance = _asMap(data['attendance']);
          final summary = _asMap(data['summary']);
          final punchIn = _asMap(attendance['punch_in']);
          final punchOut = _asMap(attendance['punch_out']);
          final punchInPhoto = punchIn['photo_url']?.toString() ?? '';
          final punchOutPhoto = punchOut['photo_url']?.toString() ?? '';
          final status = attendance['display_status']?.toString() ??
              attendance['attendance_status']?.toString() ??
              '-';
          final workingHours = attendance['working_hours']?.toString();
          final distance = attendance['total_route_distance_km'] ??
              summary['total_distance_km'];
          final hasRoute = data['has_route'] == true;

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            children: [
              PgDetailHeader(
                title: employee['full_name']?.toString() ?? '-',
                subtitle: employee['employee_code']?.toString() ?? '-',
                badgeLabel: status,
                badgeTone: PgStatusTone.info,
              ),
              const SizedBox(height: AppSpacing.md),
              PgCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Attendance Summary',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    PgInvoiceRow(
                      label: 'Date',
                      value: attendance['attendance_date']?.toString() ?? '-',
                    ),
                    PgInvoiceRow(
                      label: 'Punch In',
                      value: _formatTime(punchIn['time']),
                    ),
                    PgInvoiceRow(
                      label: 'Punch Out',
                      value: _formatTime(
                        punchOut['time'],
                        empty: 'Not Punched Out',
                      ),
                    ),
                    PgInvoiceRow(
                      label: 'Working Duration',
                      value: (workingHours == null || workingHours.isEmpty)
                          ? '-'
                          : workingHours,
                    ),
                    PgInvoiceRow(
                      label: 'Route Distance',
                      value: distance == null
                          ? '-'
                          : '${double.tryParse('$distance')?.toStringAsFixed(1) ?? distance} km',
                    ),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              PgCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Locations',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    PgInvoiceRow(
                      label: 'Punch In Location',
                      value: punchIn['location']?.toString() ?? '-',
                    ),
                    PgInvoiceRow(
                      label: 'Punch Out Location',
                      value: punchOut['location']?.toString() ?? '-',
                    ),
                  ],
                ),
              ),
              if (punchInPhoto.isNotEmpty || punchOutPhoto.isNotEmpty) ...[
                const SizedBox(height: AppSpacing.md),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (punchInPhoto.isNotEmpty)
                      Expanded(
                        child: _AttendancePhotoCard(
                          label: 'Punch In Photo',
                          url: punchInPhoto,
                        ),
                      ),
                    if (punchInPhoto.isNotEmpty && punchOutPhoto.isNotEmpty)
                      const SizedBox(width: AppSpacing.sm),
                    if (punchOutPhoto.isNotEmpty)
                      Expanded(
                        child: _AttendancePhotoCard(
                          label: 'Punch Out Photo',
                          url: punchOutPhoto,
                        ),
                      ),
                  ],
                ),
              ],
              const SizedBox(height: AppSpacing.lg),
              if (hasRoute)
                FilledButton.icon(
                  onPressed: () => context.push(
                    '/manager/team-attendance/${widget.attendanceId}/route',
                  ),
                  icon: const Icon(Icons.map_outlined),
                  label: const Text('View Route'),
                )
              else
                const PgEmptyState(
                  message: 'Route data not available for this attendance.',
                  icon: Icon(Icons.map_outlined),
                ),
              const SizedBox(height: AppSpacing.xl),
            ],
          );
        },
      ),
    );
  }
}

class _AttendancePhotoCard extends StatelessWidget {
  const _AttendancePhotoCard({required this.label, required this.url});

  final String label;
  final String url;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: AppSpacing.sm),
          ClipRRect(
            borderRadius: BorderRadius.circular(12),
            child: AspectRatio(
              aspectRatio: 1,
              child: CachedNetworkImage(
                imageUrl: url,
                fit: BoxFit.cover,
                errorWidget: (_, _, _) =>
                    const Icon(Icons.broken_image, size: 48),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class ManagerTeamRouteMapScreen extends StatefulWidget {
  const ManagerTeamRouteMapScreen({
    super.key,
    required this.auth,
    required this.attendanceId,
    this.loadRoute,
  });

  final AuthController auth;
  final int attendanceId;
  /// Optional loader so Director (and others) can reuse this map screen.
  final Future<Map<String, dynamic>> Function(int attendanceId)? loadRoute;

  @override
  State<ManagerTeamRouteMapScreen> createState() =>
      _ManagerTeamRouteMapScreenState();
}

class _ManagerTeamRouteMapScreenState extends State<ManagerTeamRouteMapScreen> {
  late Future<Map<String, dynamic>> _future;
  WebViewController? _controller;

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<Map<String, dynamic>> _load() async {
    final data = widget.loadRoute != null
        ? await widget.loadRoute!(widget.attendanceId)
        : await _api.getTeamAttendance(widget.attendanceId);
    if (!mounted) return data;
    if (data['has_route'] == true) {
      _setupController(data);
    }
    return data;
  }

  void _setupController(Map<String, dynamic> data) {
    final attendance = _asMap(data['attendance']);
    final summary = _asMap(data['summary']);
    final punchIn = _asMap(attendance['punch_in']);
    final punchOut = _asMap(attendance['punch_out']);
    final routePoints = ((data['route_points'] as List?) ?? const [])
        .map((item) => Map<String, dynamic>.from(item as Map))
        .toList();

    final html = _buildLeafletHtml(
      routePoints: routePoints,
      punchIn: punchIn,
      punchOut: punchOut,
      summary: summary,
      distanceKm: attendance['total_route_distance_km'],
    );

    final controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..loadHtmlString(html);

    setState(() => _controller = controller);
  }

  Map<String, dynamic> _asMap(Object? value) {
    if (value is Map) return Map<String, dynamic>.from(value);
    return const {};
  }

  String _formatTime(Object? value) {
    if (value == null) return '-';
    final parsed = DateTime.tryParse(value.toString());
    if (parsed == null) return value.toString();
    return DateFormat('hh:mm a').format(parsed.toLocal());
  }

  String _buildLeafletHtml({
    required List<Map<String, dynamic>> routePoints,
    required Map<String, dynamic> punchIn,
    required Map<String, dynamic> punchOut,
    required Map<String, dynamic> summary,
    required Object? distanceKm,
  }) {
    final points = <List<double>>[];
    for (final point in routePoints) {
      final lat = double.tryParse('${point['latitude']}');
      final lng = double.tryParse('${point['longitude']}');
      if (lat != null && lng != null) points.add([lat, lng]);
    }

    void addPoint(Map<String, dynamic> source) {
      final lat = double.tryParse('${source['latitude']}');
      final lng = double.tryParse('${source['longitude']}');
      if (lat != null && lng != null) points.add([lat, lng]);
    }

    addPoint(punchIn);
    addPoint(punchOut);

    final center = points.isNotEmpty ? points.first : [19.8762, 75.3433];
    final encodedPoints = jsonEncode(points);
    final punchInLat = punchIn['latitude'];
    final punchInLng = punchIn['longitude'];
    final punchOutLat = punchOut['latitude'];
    final punchOutLng = punchOut['longitude'];
    final distance = distanceKm ?? summary['total_distance_km'] ?? '-';

    return '''
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <style>
    html, body, #map { height: 100%; margin: 0; }
  </style>
</head>
<body>
  <div id="map"></div>
  <script>
    const map = L.map('map').setView([${center[0]}, ${center[1]}], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    const points = $encodedPoints;
    if (points.length > 1) {
      const line = L.polyline(points, { color: '#0B6E4F', weight: 4 }).addTo(map);
      map.fitBounds(line.getBounds(), { padding: [28, 28] });
    }

    ${punchInLat != null && punchInLng != null ? "L.marker([$punchInLat, $punchInLng]).addTo(map).bindPopup('Punch In');" : ''}
    ${punchOutLat != null && punchOutLng != null ? "L.marker([$punchOutLat, $punchOutLng]).addTo(map).bindPopup('Punch Out');" : ''}

    L.control.attribution({prefix: false}).addTo(map);
    const info = L.control({position: 'bottomleft'});
    info.onAdd = function() {
      const div = L.DomUtil.create('div');
      div.style.background = 'white';
      div.style.padding = '8px 10px';
      div.style.borderRadius = '8px';
      div.style.boxShadow = '0 1px 4px rgba(0,0,0,0.2)';
      div.innerHTML = '<b>Distance:</b> $distance km';
      return div;
    };
    info.addTo(map);
  </script>
</body>
</html>
''';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'Employee Route', auth: widget.auth),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(
              message: errorMessage(snapshot.error),
              onRetry: () async {
                setState(() => _future = _load());
                await _future;
              },
            );
          }

          final data = snapshot.data!;
          if (data['has_route'] != true || _controller == null) {
            return const PgEmptyState(
              message: 'Route data not available for this attendance.',
              icon: Icon(Icons.map_outlined),
            );
          }

          final employee = _asMap(data['employee']);
          final attendance = _asMap(data['attendance']);
          final punchIn = _asMap(attendance['punch_in']);
          final punchOut = _asMap(attendance['punch_out']);
          final distance = attendance['total_route_distance_km'] ??
              _asMap(data['summary'])['total_distance_km'];

          return Column(
            children: [
              Padding(
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                child: PgCard(
                  child: Column(
                    children: [
                      PgInvoiceRow(
                        label: 'Employee',
                        value: employee['full_name']?.toString() ?? '-',
                      ),
                      PgInvoiceRow(
                        label: 'Date',
                        value: attendance['attendance_date']?.toString() ?? '-',
                      ),
                      PgInvoiceRow(
                        label: 'Punch In',
                        value: _formatTime(punchIn['time']),
                      ),
                      PgInvoiceRow(
                        label: 'Punch Out',
                        value: _formatTime(punchOut['time']),
                      ),
                      PgInvoiceRow(
                        label: 'Distance',
                        value: distance == null
                            ? '-'
                            : '${double.tryParse('$distance')?.toStringAsFixed(1) ?? distance} km',
                      ),
                    ],
                  ),
                ),
              ),
              Expanded(child: WebViewWidget(controller: _controller!)),
            ],
          );
        },
      ),
    );
  }
}
