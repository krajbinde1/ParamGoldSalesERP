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

class ManagerCollectionsScreen extends StatefulWidget {
  const ManagerCollectionsScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<ManagerCollectionsScreen> createState() =>
      _ManagerCollectionsScreenState();
}

class _ManagerCollectionsScreenState extends State<ManagerCollectionsScreen> {
  String _period = 'month';
  int? _employeeId;
  DateTime? _dateFrom;
  DateTime? _dateTo;
  late Future<ManagerCollectionListResult> _future;

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  static final _currency = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 0,
  );

  @override
  void initState() {
    super.initState();
    _reload();
  }

  void _reload() {
    setState(() {
      _future = _api.listCollections(
        period: _period,
        dateFrom: _period == 'custom' && _dateFrom != null
            ? DateFormat('yyyy-MM-dd').format(_dateFrom!)
            : null,
        dateTo: _period == 'custom' && _dateTo != null
            ? DateFormat('yyyy-MM-dd').format(_dateTo!)
            : null,
        employeeId: _employeeId,
      );
    });
  }

  Future<void> _refresh() async {
    _reload();
    await _future;
  }

  Future<void> _setPeriod(String period) async {
    if (period == 'custom') {
      final from = await showDatePicker(
        context: context,
        initialDate: _dateFrom ?? DateTime.now(),
        firstDate: DateTime(2020),
        lastDate: DateTime.now(),
      );
      if (!mounted || from == null) return;
      final to = await showDatePicker(
        context: context,
        initialDate: _dateTo ?? from,
        firstDate: from,
        lastDate: DateTime.now(),
      );
      if (!mounted || to == null) return;
      setState(() {
        _period = 'custom';
        _dateFrom = from;
        _dateTo = to;
      });
      _reload();
      return;
    }

    setState(() {
      _period = period;
      _dateFrom = null;
      _dateTo = null;
    });
    _reload();
  }

  String _employeeChipLabel(List<Map<String, dynamic>> employees) {
    if (_employeeId == null) return 'Employee';
    Map<String, dynamic>? match;
    for (final employee in employees) {
      if (int.tryParse('${employee['id']}') == _employeeId) {
        match = employee;
        break;
      }
    }
    final name = match?['full_name']?.toString().trim();
    if (name == null || name.isEmpty) return 'Employee';
    return 'Employee: $name';
  }

  String get _customChipLabel {
    if (_period == 'custom' && _dateFrom != null && _dateTo != null) {
      return 'Custom: ${DateFormat('d MMM').format(_dateFrom!)} – ${DateFormat('d MMM').format(_dateTo!)}';
    }
    return 'Custom';
  }

  Future<void> _pickEmployee(List<Map<String, dynamic>> employees) async {
    final result = await showModalBottomSheet<Object>(
      context: context,
      builder: (context) => SafeArea(
        child: ListView(
          children: [
            ListTile(
              title: const Text('All employees'),
              selected: _employeeId == null,
              onTap: () => Navigator.pop(context, 'all'),
            ),
            for (final employee in employees)
              ListTile(
                title: Text(
                  [
                    employee['full_name']?.toString() ?? '-',
                    if ((employee['employee_code']?.toString().isNotEmpty ??
                        false))
                      '(${employee['employee_code']})',
                  ].join(' '),
                ),
                selected: int.tryParse('${employee['id']}') == _employeeId,
                onTap: () => Navigator.pop(
                  context,
                  int.tryParse('${employee['id']}'),
                ),
              ),
          ],
        ),
      ),
    );
    if (!mounted || result == null) return;
    setState(() {
      _employeeId = result == 'all' ? null : result as int?;
    });
    _reload();
  }

  PgStatusTone _statusTone(String status) {
    return switch (status) {
      'received' => PgStatusTone.paid,
      'not_received' => PgStatusTone.rejected,
      _ => PgStatusTone.pending,
    };
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: RoleAppBar(title: 'Collections', auth: widget.auth),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: FutureBuilder<ManagerCollectionListResult>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting &&
                !snapshot.hasData) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: const [PgLoadingState()],
              );
            }

            if (snapshot.hasError) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  PgErrorState(
                    message: errorMessage(snapshot.error),
                    onRetry: _refresh,
                  ),
                ],
              );
            }

            final result = snapshot.data!;
            final employees = result.employees;
            final summary = result.summary;

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                _SummaryGrid(
                  tiles: [
                    _SummaryTile(
                      label: 'Total Collection',
                      value: _currency.format(summary.totalCollection),
                      icon: Icons.payments_rounded,
                      accent: AppColors.primary,
                    ),
                    _SummaryTile(
                      label: 'Today Collection',
                      value: _currency.format(summary.todayCollection),
                      icon: Icons.today_rounded,
                      accent: AppColors.info,
                    ),
                    _SummaryTile(
                      label: 'This Month Collection',
                      value: _currency.format(summary.monthCollection),
                      icon: Icons.calendar_month_rounded,
                      accent: AppColors.secondary,
                    ),
                    _SummaryTile(
                      label: 'Pending Collection Entries',
                      value: '${summary.pendingEntries}',
                      icon: Icons.hourglass_empty_rounded,
                      accent: AppColors.warning,
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.md),
                ManagerScrollableFilters(
                  children: [
                    for (final entry in const [
                      ('today', 'Today'),
                      ('week', 'This Week'),
                      ('month', 'This Month'),
                    ])
                      ManagerFilterChip(
                        label: entry.$2,
                        selected: _period == entry.$1,
                        onPressed: () {
                          if (_period == entry.$1) return;
                          _setPeriod(entry.$1);
                        },
                      ),
                    ManagerFilterChip(
                      label: _customChipLabel,
                      selected: _period == 'custom',
                      onPressed: () => _setPeriod('custom'),
                    ),
                    ManagerFilterChip(
                      label: _employeeChipLabel(employees),
                      selected: _employeeId != null,
                      onPressed: () => _pickEmployee(employees),
                      onClear: _employeeId == null
                          ? null
                          : () {
                              setState(() => _employeeId = null);
                              _reload();
                            },
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.lg),
                const PgSectionHeader(title: 'Team Collections'),
                if (result.rows.isEmpty)
                  const PgEmptyState(
                    message: 'No collections found for the selected filters.',
                    icon: Icon(Icons.payments_outlined),
                  )
                else
                  for (final row in result.rows)
                    _CollectionListRow(
                      dealerName: row['dealer_name']?.toString() ?? '-',
                      amount: _currency.format(
                        double.tryParse('${row['amount'] ?? 0}') ?? 0,
                      ),
                      employeeName: row['employee_name']?.toString() ?? '-',
                      dateLabel: _formatDate(
                        row['collection_date']?.toString(),
                      ),
                      statusLabel: row['status_label']?.toString() ??
                          row['status']?.toString() ??
                          'Pending Verification',
                      statusTone: _statusTone(
                        row['status']?.toString() ?? 'pending',
                      ),
                      onTap: () => context.push(
                        '/manager/collections/${row['id']}',
                      ),
                    ),
              ],
            );
          },
        ),
      ),
    );
  }

  String _formatDate(String? raw) {
    if (raw == null || raw.isEmpty) return '-';
    final parsed = DateTime.tryParse(raw);
    if (parsed == null) return raw;
    return DateFormat('d MMM yyyy').format(parsed);
  }
}

class _SummaryGrid extends StatelessWidget {
  const _SummaryGrid({required this.tiles});

  final List<Widget> tiles;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final gap = AppSpacing.sm;
        final maxWidth = constraints.maxWidth;
        final twoCol = maxWidth >= 320;
        if (!twoCol) {
          return Column(
            children: [
              for (var i = 0; i < tiles.length; i++) ...[
                if (i > 0) SizedBox(height: gap),
                tiles[i],
              ],
            ],
          );
        }

        return Column(
          children: [
            for (var i = 0; i < tiles.length; i += 2) ...[
              if (i > 0) SizedBox(height: gap),
              IntrinsicHeight(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Expanded(child: tiles[i]),
                    SizedBox(width: gap),
                    Expanded(
                      child: i + 1 < tiles.length
                          ? tiles[i + 1]
                          : const SizedBox.shrink(),
                    ),
                  ],
                ),
              ),
            ],
          ],
        );
      },
    );
  }
}

class _SummaryTile extends StatelessWidget {
  const _SummaryTile({
    required this.label,
    required this.value,
    required this.icon,
    required this.accent,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 20, color: accent),
          const SizedBox(height: 8),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              value,
              maxLines: 1,
              style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
            ),
          ),
          const SizedBox(height: 4),
          Text(
            label,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.labelMedium?.copyWith(
                  color: AppColors.textSecondary,
                  fontWeight: FontWeight.w600,
                  height: 1.25,
                ),
          ),
        ],
      ),
    );
  }
}

class _CollectionListRow extends StatelessWidget {
  const _CollectionListRow({
    required this.dealerName,
    required this.amount,
    required this.employeeName,
    required this.dateLabel,
    required this.statusLabel,
    required this.statusTone,
    required this.onTap,
  });

  final String dealerName;
  final String amount;
  final String employeeName;
  final String dateLabel;
  final String statusLabel;
  final PgStatusTone statusTone;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      padding: const EdgeInsets.fromLTRB(14, 10, 8, 10),
      onTap: onTap,
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  dealerName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                ),
                const SizedBox(height: 2),
                Text(
                  amount,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                ),
                const SizedBox(height: 2),
                Text(
                  '$employeeName • $dateLabel',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AppColors.textMuted,
                        fontWeight: FontWeight.w500,
                      ),
                ),
                const SizedBox(height: 8),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: PgStatusBadge(label: statusLabel, tone: statusTone),
                ),
              ],
            ),
          ),
          const Icon(
            Icons.chevron_right_rounded,
            color: AppColors.textMuted,
          ),
        ],
      ),
    );
  }
}
