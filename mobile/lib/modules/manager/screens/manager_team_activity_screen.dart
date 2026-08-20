import 'package:cached_network_image/cached_network_image.dart';
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
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/manager_api.dart';
import '../widgets/manager_scrollable_filters.dart';
import '../widgets/view_captured_location_button.dart';

class ManagerTeamActivityScreen extends StatefulWidget {
  const ManagerTeamActivityScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<ManagerTeamActivityScreen> createState() =>
      _ManagerTeamActivityScreenState();
}

class _ManagerTeamActivityScreenState extends State<ManagerTeamActivityScreen> {
  late DateTime _date;
  String _type = 'all';
  String _search = '';
  final _searchController = TextEditingController();
  late Future<ManagerTeamActivityListResult> _future;

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _date = DateTime.now();
    _reload();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  String get _dateParam => DateFormat('yyyy-MM-dd').format(_date);

  void _reload() {
    setState(() {
      _future = _api.listTeamActivity(
        date: _dateParam,
        search: _search,
        type: _type,
      );
    });
  }

  Future<void> _refresh() async {
    _reload();
    await _future;
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: DateTime.now().subtract(const Duration(days: 730)),
      lastDate: DateTime.now(),
    );
    if (picked == null || !mounted) return;
    setState(() => _date = picked);
    _reload();
  }

  void _setType(String type) {
    if (_type == type) return;
    setState(() => _type = type);
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final isToday = DateUtils.isSameDay(_date, DateTime.now());

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: RoleAppBar(title: 'Team Activity', auth: widget.auth),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: _pickDate,
                        icon: const Icon(Icons.calendar_today_rounded, size: 18),
                        label: Text(
                          isToday
                              ? 'Today · ${DateFormat('d MMM yyyy').format(_date)}'
                              : DateFormat('EEE, d MMM yyyy').format(_date),
                        ),
                      ),
                    ),
                    if (!isToday) ...[
                      const SizedBox(width: 8),
                      TextButton(
                        onPressed: () {
                          setState(() => _date = DateTime.now());
                          _reload();
                        },
                        child: const Text('Today'),
                      ),
                    ],
                  ],
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: _searchController,
                  decoration: InputDecoration(
                    hintText: 'Search employee name / code',
                    prefixIcon: const Icon(Icons.search_rounded),
                    suffixIcon: _search.isEmpty
                        ? null
                        : IconButton(
                            onPressed: () {
                              _searchController.clear();
                              setState(() => _search = '');
                              _reload();
                            },
                            icon: const Icon(Icons.clear_rounded),
                          ),
                  ),
                  textInputAction: TextInputAction.search,
                  onChanged: (value) => setState(() => _search = value.trim()),
                  onSubmitted: (_) => _reload(),
                ),
                const SizedBox(height: 10),
                ManagerScrollableFilters(
                  children: [
                    for (final entry in const [
                      ('all', 'All'),
                      ('dealer_visit', 'Dealer Visits'),
                      ('field_visit', 'Field Activities'),
                    ])
                      ManagerFilterChip(
                        label: entry.$2,
                        selected: _type == entry.$1,
                        onPressed: () => _setType(entry.$1),
                      ),
                  ],
                ),
              ],
            ),
          ),
          Expanded(
            child: FutureBuilder<ManagerTeamActivityListResult>(
              future: _future,
              builder: (context, snapshot) {
                if (snapshot.connectionState == ConnectionState.waiting &&
                    !snapshot.hasData) {
                  return const PgLoadingState();
                }
                if (snapshot.hasError) {
                  return RefreshIndicator(
                    onRefresh: _refresh,
                    child: ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.all(AppSpacing.screenPadding),
                      children: [
                        PgErrorState(
                          message: errorMessage(snapshot.error),
                          onRetry: _refresh,
                        ),
                      ],
                    ),
                  );
                }

                final result = snapshot.data!;
                final rows = result.rows;

                return RefreshIndicator(
                  onRefresh: _refresh,
                  child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                    children: [
                      LayoutBuilder(
                        builder: (context, constraints) {
                          final aspect =
                              constraints.maxWidth >= 400 ? 2.1 : 1.7;
                          return GridView.count(
                            crossAxisCount: 2,
                            shrinkWrap: true,
                            physics: const NeverScrollableScrollPhysics(),
                            mainAxisSpacing: AppSpacing.sm,
                            crossAxisSpacing: AppSpacing.sm,
                            childAspectRatio: aspect,
                            children: [
                              _StatCard(
                                label: 'Dealer Visits',
                                value: '${result.totalDealerVisits}',
                                icon: Icons.storefront_rounded,
                                color: AppColors.primary,
                              ),
                              _StatCard(
                                label: 'Field Activities',
                                value: '${result.totalFieldVisits}',
                                icon: Icons.agriculture_rounded,
                                color: AppColors.success,
                              ),
                            ],
                          );
                        },
                      ),
                      if (result.activeEmployees > 0) ...[
                        const SizedBox(height: AppSpacing.sm),
                        Text(
                          'Active Employees: ${result.activeEmployees}',
                          style:
                              Theme.of(context).textTheme.labelLarge?.copyWith(
                                    color: AppColors.textSecondary,
                                    fontWeight: FontWeight.w600,
                                  ),
                        ),
                      ],
                      const SizedBox(height: AppSpacing.md),
                      const PgSectionHeader(title: 'Employee Activity'),
                      if (rows.isEmpty)
                        PgEmptyState(
                          message: isToday
                              ? 'No team activity recorded today.'
                              : 'No Dealer Visits or Field Activities recorded for this date.',
                          icon: const Icon(Icons.travel_explore_outlined),
                        )
                      else
                        for (final row in rows)
                          _EmployeeActivityCard(
                            row: row,
                            onView: () async {
                              await context.push(
                                '/manager/team-activity/employees/${row['employee_id']}',
                                extra: {
                                  'date': _dateParam,
                                  'type': _type,
                                  'name': row['employee_name'],
                                  'code': row['employee_code'],
                                },
                              );
                              if (!mounted) return;
                              _refresh();
                            },
                          ),
                    ],
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

class _StatCard extends StatelessWidget {
  const _StatCard({
    required this.label,
    required this.value,
    required this.icon,
    required this.color,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: color, size: 22),
          const Spacer(),
          Text(
            value,
            style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
          ),
          Text(
            label,
            style: Theme.of(context).textTheme.labelMedium?.copyWith(
                  color: AppColors.textSecondary,
                  fontWeight: FontWeight.w600,
                ),
          ),
        ],
      ),
    );
  }
}

class _EmployeeActivityCard extends StatelessWidget {
  const _EmployeeActivityCard({
    required this.row,
    required this.onView,
  });

  final Map<String, dynamic> row;
  final VoidCallback onView;

  @override
  Widget build(BuildContext context) {
    final dealerCount = int.tryParse('${row['dealer_visit_count'] ?? 0}') ?? 0;
    final fieldCount = int.tryParse('${row['field_visit_count'] ?? 0}') ?? 0;
    final lastType = row['last_activity_type_label']?.toString();
    final lastTime = _formatDisplayTime(row['last_activity_time']?.toString());

    return PgCard(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      onTap: onView,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            row['employee_name']?.toString() ?? '-',
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
          ),
          const SizedBox(height: 2),
          Text(
            row['employee_code']?.toString() ?? '-',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
          ),
          const SizedBox(height: AppSpacing.sm),
          Row(
            children: [
              Expanded(
                child: Text(
                  'Dealer Visits: $dealerCount',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                      ),
                ),
              ),
              Expanded(
                child: Text(
                  'Field Activities: $fieldCount',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                      ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            'Last Activity: ${lastType ?? '—'}',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
          ),
          Text(
            'Last Activity Time: ${lastTime ?? '—'}',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
          ),
          const SizedBox(height: AppSpacing.sm),
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton.tonal(
              onPressed: onView,
              child: const Text('View Activity'),
            ),
          ),
        ],
      ),
    );
  }
}

class ManagerEmployeeTeamActivityScreen extends StatefulWidget {
  const ManagerEmployeeTeamActivityScreen({
    super.key,
    required this.auth,
    required this.employeeId,
    required this.date,
    this.type = 'all',
    this.employeeName,
    this.employeeCode,
  });

  final AuthController auth;
  final int employeeId;
  final String date;
  final String type;
  final String? employeeName;
  final String? employeeCode;

  @override
  State<ManagerEmployeeTeamActivityScreen> createState() =>
      _ManagerEmployeeTeamActivityScreenState();
}

class _ManagerEmployeeTeamActivityScreenState
    extends State<ManagerEmployeeTeamActivityScreen> {
  late Future<ManagerTeamActivityTimelineResult> _future;
  late String _type;

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _type = widget.type;
    _reload();
  }

  void _reload() {
    setState(() {
      _future = _api.getEmployeeTeamActivity(
        widget.employeeId,
        date: widget.date,
        type: _type,
      );
    });
  }

  Future<void> _refresh() async {
    _reload();
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: RoleAppBar(title: 'Employee Activity', auth: widget.auth),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: ManagerScrollableFilters(
              children: [
                for (final entry in const [
                  ('all', 'All'),
                  ('dealer_visit', 'Dealer Visits'),
                  ('field_visit', 'Field Activities'),
                ])
                  ManagerFilterChip(
                    label: entry.$2,
                    selected: _type == entry.$1,
                    onPressed: () {
                      if (_type == entry.$1) return;
                      setState(() => _type = entry.$1);
                      _reload();
                    },
                  ),
              ],
            ),
          ),
          Expanded(
            child: FutureBuilder<ManagerTeamActivityTimelineResult>(
              future: _future,
              builder: (context, snapshot) {
                if (snapshot.connectionState == ConnectionState.waiting &&
                    !snapshot.hasData) {
                  return const PgLoadingState();
                }
                if (snapshot.hasError) {
                  return RefreshIndicator(
                    onRefresh: _refresh,
                    child: ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.all(AppSpacing.screenPadding),
                      children: [
                        PgErrorState(
                          message: errorMessage(snapshot.error),
                          onRetry: _refresh,
                        ),
                      ],
                    ),
                  );
                }

                final result = snapshot.data!;
                final name = result.employee['full_name']?.toString() ??
                    widget.employeeName ??
                    '-';
                final code = result.employee['employee_code']?.toString() ??
                    widget.employeeCode ??
                    '-';
                final rows = result.rows;

                return RefreshIndicator(
                  onRefresh: _refresh,
                  child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(AppSpacing.screenPadding),
                    children: [
                      PgCard(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              name,
                              style: Theme.of(context)
                                  .textTheme
                                  .titleLarge
                                  ?.copyWith(fontWeight: FontWeight.w800),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              code,
                              style: Theme.of(context)
                                  .textTheme
                                  .bodyMedium
                                  ?.copyWith(color: AppColors.textSecondary),
                            ),
                            const SizedBox(height: 8),
                            Text(
                              'Date: ${widget.date}',
                              style: Theme.of(context).textTheme.labelLarge,
                            ),
                            const SizedBox(height: 4),
                            Text(
                              'Dealer Visits: ${result.dealerVisitCount}  ·  Field Activities: ${result.fieldVisitCount}',
                              style: Theme.of(context)
                                  .textTheme
                                  .bodySmall
                                  ?.copyWith(color: AppColors.textSecondary),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: AppSpacing.md),
                      const PgSectionHeader(title: 'Activity Timeline'),
                      if (rows.isEmpty)
                        const PgEmptyState(
                          message:
                              'No Dealer Visits or Field Activities recorded for this date.',
                          icon: Icon(Icons.event_busy_outlined),
                        )
                      else
                        for (final item in rows)
                          _TimelineCard(
                            item: item,
                            employeeName: name,
                            onTap: () => _openDetail(context, item, name),
                          ),
                    ],
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  void _openDetail(
    BuildContext context,
    Map<String, dynamic> item, [
    String? employeeName,
  ]) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppColors.surface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (context) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.72,
        minChildSize: 0.45,
        maxChildSize: 0.92,
        builder: (context, controller) => _ActivityDetailSheet(
          item: item,
          employeeName: employeeName ?? item['employee_name']?.toString(),
          scrollController: controller,
        ),
      ),
    );
  }
}

class _TimelineCard extends StatelessWidget {
  const _TimelineCard({
    required this.item,
    required this.onTap,
    this.employeeName,
  });

  final Map<String, dynamic> item;
  final VoidCallback onTap;
  final String? employeeName;

  @override
  Widget build(BuildContext context) {
    final isDealer = item['type']?.toString() == 'dealer_visit';
    final time = _formatDisplayTime(item['activity_time']?.toString()) ?? '—';
    final dealer = Map<String, dynamic>.from(item['dealer'] as Map? ?? const {});
    final field = Map<String, dynamic>.from(item['field'] as Map? ?? const {});
    final name = item['employee_name']?.toString() ?? employeeName;

    return PgCard(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          InkWell(
            onTap: onTap,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
          Row(
            children: [
              Text(
                time,
                style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
              ),
              const Spacer(),
              PgStatusBadge(
                label: item['type_label']?.toString() ??
                    (isDealer ? 'Dealer Visit' : 'Field Activity'),
                tone: isDealer ? PgStatusTone.info : PgStatusTone.approved,
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          if ((name?.isNotEmpty ?? false)) _DetailLine('Employee', name!),
          if (isDealer) ...[
            _DetailLine('Dealer', dealer['name']?.toString() ?? '-'),
            if ((dealer['code']?.toString().isNotEmpty ?? false))
              _DetailLine('Dealer Code', dealer['code'].toString()),
          ] else ...[
            _DetailLine(
              'Activity Type',
              item['type_label']?.toString() ?? 'Field Activity',
            ),
            if ((field['farmer_name']?.toString().isNotEmpty ?? false))
              _DetailLine('Farmer', field['farmer_name'].toString()),
            if ((field['farmer_mobile']?.toString().isNotEmpty ?? false))
              _DetailLine('Mobile', field['farmer_mobile'].toString()),
            if ((field['district']?.toString().isNotEmpty ?? false))
              _DetailLine('District', field['district'].toString()),
            if ((field['village']?.toString().isNotEmpty ?? false))
              _DetailLine('Village', field['village'].toString()),
            if ((field['taluka']?.toString().isNotEmpty ?? false))
              _DetailLine('Taluka', field['taluka'].toString()),
            if ((field['crop_name']?.toString().isNotEmpty ?? false))
              _DetailLine('Crop', field['crop_name'].toString()),
            ..._recommendationLines(field),
          ],
          _DetailLine('Date', item['activity_date']?.toString() ?? '-'),
          _DetailLine('Time', time),
          if ((item['location']?.toString().isNotEmpty ?? false))
            _DetailLine('Location', item['location'].toString()),
          if (item['latitude'] != null)
            _DetailLine('Latitude', '${item['latitude']}'),
          if (item['longitude'] != null)
            _DetailLine('Longitude', '${item['longitude']}'),
          if ((item['remark']?.toString().isNotEmpty ?? false))
            _DetailLine('Remark', item['remark'].toString()),
              ],
            ),
          ),
          const SizedBox(height: 8),
          ViewCapturedLocationButton(
            mapsUrl: item['maps_url'],
            latitude: item['latitude'],
            longitude: item['longitude'],
            locationAvailable: item['location_available'],
          ),
          if ((item['photo_url']?.toString().isNotEmpty ?? false)) ...[
            const SizedBox(height: 8),
            ClipRRect(
              borderRadius: BorderRadius.circular(12),
              child: AspectRatio(
                aspectRatio: 16 / 9,
                child: CachedNetworkImage(
                  imageUrl: item['photo_url'].toString(),
                  fit: BoxFit.cover,
                  errorWidget: (_, _, _) => Container(
                    color: AppColors.border,
                    alignment: Alignment.center,
                    child: const Icon(Icons.broken_image_outlined),
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _ActivityDetailSheet extends StatelessWidget {
  const _ActivityDetailSheet({
    required this.item,
    required this.scrollController,
    this.employeeName,
  });

  final Map<String, dynamic> item;
  final ScrollController scrollController;
  final String? employeeName;

  @override
  Widget build(BuildContext context) {
    final isDealer = item['type']?.toString() == 'dealer_visit';
    final dealer = Map<String, dynamic>.from(item['dealer'] as Map? ?? const {});
    final field = Map<String, dynamic>.from(item['field'] as Map? ?? const {});
    final lat = item['latitude'];
    final lng = item['longitude'];
    final name = item['employee_name']?.toString() ?? employeeName;

    return ListView(
      controller: scrollController,
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 28),
      children: [
        Center(
          child: Container(
            width: 40,
            height: 4,
            decoration: BoxDecoration(
              color: AppColors.border,
              borderRadius: BorderRadius.circular(99),
            ),
          ),
        ),
        const SizedBox(height: 16),
        Text(
          item['type_label']?.toString() ??
              (isDealer ? 'Dealer Visit' : 'Field Activity'),
          style: Theme.of(context).textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w800,
              ),
        ),
        const SizedBox(height: 12),
        if ((name?.isNotEmpty ?? false)) _DetailLine('Employee', name!),
        _DetailLine(
          'Time',
          _formatDisplayTime(item['activity_time']?.toString()) ?? '-',
        ),
        _DetailLine('Date', item['activity_date']?.toString() ?? '-'),
        if ((item['status_label']?.toString().isNotEmpty ?? false))
          _DetailLine('Status', item['status_label'].toString()),
        if (isDealer) ...[
          _DetailLine('Dealer', dealer['name']?.toString() ?? '-'),
          if ((dealer['code']?.toString().isNotEmpty ?? false))
            _DetailLine('Dealer Code', dealer['code'].toString()),
          if ((dealer['owner_name']?.toString().isNotEmpty ?? false))
            _DetailLine('Owner', dealer['owner_name'].toString()),
          if ((dealer['address']?.toString().isNotEmpty ?? false))
            _DetailLine('Address', dealer['address'].toString()),
        ] else ...[
          _DetailLine(
            'Activity Type',
            item['type_label']?.toString() ?? 'Field Activity',
          ),
          if ((field['farmer_name']?.toString().isNotEmpty ?? false))
            _DetailLine('Farmer', field['farmer_name'].toString()),
          if ((field['farmer_mobile']?.toString().isNotEmpty ?? false))
            _DetailLine('Mobile', field['farmer_mobile'].toString()),
          if ((field['district']?.toString().isNotEmpty ?? false))
            _DetailLine('District', field['district'].toString()),
          if ((field['village']?.toString().isNotEmpty ?? false))
            _DetailLine('Village', field['village'].toString()),
          if ((field['taluka']?.toString().isNotEmpty ?? false))
            _DetailLine('Taluka', field['taluka'].toString()),
          if ((field['crop_name']?.toString().isNotEmpty ?? false))
            _DetailLine('Crop', field['crop_name'].toString()),
          ..._recommendationLines(field),
        ],
        if ((item['location']?.toString().isNotEmpty ?? false))
          _DetailLine('Location', item['location'].toString()),
        if (lat != null) _DetailLine('Latitude', '$lat'),
        if (lng != null) _DetailLine('Longitude', '$lng'),
        if ((item['remark']?.toString().isNotEmpty ?? false))
          _DetailLine('Remark / Description', item['remark'].toString()),
        const SizedBox(height: 12),
        ViewCapturedLocationButton(
          mapsUrl: item['maps_url'],
          latitude: lat,
          longitude: lng,
          locationAvailable: item['location_available'],
        ),
        if ((item['photo_url']?.toString().isNotEmpty ?? false)) ...[
          const SizedBox(height: 12),
          ClipRRect(
            borderRadius: BorderRadius.circular(14),
            child: CachedNetworkImage(
              imageUrl: item['photo_url'].toString(),
              fit: BoxFit.cover,
              errorWidget: (_, _, _) => Container(
                height: 160,
                color: AppColors.border,
                alignment: Alignment.center,
                child: const Icon(Icons.broken_image_outlined),
              ),
            ),
          ),
        ],
      ],
    );
  }
}

class _DetailLine extends StatelessWidget {
  const _DetailLine(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 96,
            child: Text(
              label,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.textMuted,
                    fontWeight: FontWeight.w600,
                  ),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
            ),
          ),
        ],
      ),
    );
  }
}

List<Widget> _recommendationLines(Map field) {
  final recs = field['recommendations'];
  if (recs is! List || recs.isEmpty) return const [];

  return [
    for (final rec in recs)
      _DetailLine(
        'Product',
        [
          rec is Map ? rec['product_name']?.toString() ?? '-' : '-',
          if (rec is Map && (rec['dosage']?.toString().isNotEmpty ?? false))
            rec['dosage'].toString(),
          if (rec is Map && (rec['remark']?.toString().isNotEmpty ?? false))
            rec['remark'].toString(),
        ].join(' • '),
      ),
  ];
}

String? _formatDisplayTime(String? raw) {
  if (raw == null || raw.trim().isEmpty) return null;
  try {
    final parsed = DateFormat('HH:mm').parse(raw.trim());
    return DateFormat('hh:mm a').format(parsed);
  } catch (_) {
    try {
      final parsed = DateFormat('H:mm').parse(raw.trim());
      return DateFormat('hh:mm a').format(parsed);
    } catch (_) {
      return raw;
    }
  }
}
