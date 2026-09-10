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
import '../../payment_follow_ups/models/payment_follow_up.dart';
import '../api/director_api.dart';

final _inr = NumberFormat.currency(
  locale: 'en_IN',
  symbol: '₹',
  decimalDigits: 0,
);
final _date = DateFormat('dd MMM yyyy');

String _formatDate(String? value) {
  if (value == null || value.isEmpty) return '—';
  final parsed = DateTime.tryParse(value);
  return parsed == null ? value : _date.format(parsed);
}

String _compactInr(double amount) {
  final sign = amount < 0 ? '-' : '';
  final abs = amount.abs();
  if (abs >= 10000000) {
    return '$sign₹${(abs / 10000000).toStringAsFixed(2)} Cr';
  }
  if (abs >= 100000) {
    return '$sign₹${(abs / 100000).toStringAsFixed(2)} L';
  }
  if (abs >= 1000) {
    return '$sign₹${(abs / 1000).toStringAsFixed(2)} K';
  }
  return _inr.format(amount);
}

String _moneyLabel(Map<String, dynamic> row, String numberKey, String labelKey) {
  final labeled = row[labelKey]?.toString();
  if (labeled != null && labeled.trim().isNotEmpty) {
    final amount = double.tryParse('${row[numberKey] ?? ''}');
    if (amount != null) return _compactInr(amount);
    return labeled;
  }
  return _compactInr(double.tryParse('${row[numberKey] ?? 0}') ?? 0);
}

PgStatusTone _statusTone(String status) => switch (status) {
      'overdue' => PgStatusTone.rejected,
      'due_today' => PgStatusTone.pending,
      'closed' => PgStatusTone.paid,
      'high' => PgStatusTone.rejected,
      'medium' => PgStatusTone.pending,
      'low' => PgStatusTone.paid,
      'missed' => PgStatusTone.rejected,
      'kept' => PgStatusTone.paid,
      'pending' => PgStatusTone.info,
      _ => PgStatusTone.neutral,
    };

class DirectorPaymentFollowUpStatusScreen extends StatefulWidget {
  const DirectorPaymentFollowUpStatusScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<DirectorPaymentFollowUpStatusScreen> createState() =>
      _DirectorPaymentFollowUpStatusScreenState();
}

class _DirectorPaymentFollowUpStatusScreenState
    extends State<DirectorPaymentFollowUpStatusScreen> {
  late Future<Map<String, dynamic>> _future;
  int? _employeeId;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<Map<String, dynamic>> _load() =>
      _api.listPaymentFollowUps(employeeId: _employeeId);

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  void _selectEmployee(int? employeeId) {
    setState(() {
      _employeeId = employeeId;
      _future = _load();
    });
  }

  Future<void> _openDealer(Map<String, dynamic> dealer) async {
    final dealerId = int.tryParse('${dealer['dealer_id'] ?? 0}') ?? 0;
    if (dealerId <= 0) return;
    await context.push('/director/payment-follow-ups/$dealerId');
    if (!mounted) return;
    await _reload();
  }

  void _openActionList({
    required String title,
    required List<Map<String, dynamic>> dealers,
  }) {
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => _FilteredDealerListPage(
          auth: widget.auth,
          title: title,
          dealers: dealers,
          onOpenDealer: _openDealer,
        ),
      ),
    );
  }

  List<Map<String, dynamic>> _maps(dynamic raw) {
    return (raw as List? ?? const [])
        .whereType<Map>()
        .map((item) => Map<String, dynamic>.from(item))
        .toList();
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
          title: 'Payment Recovery',
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
              final children = <Widget>[];
              if (snapshot.connectionState == ConnectionState.waiting &&
                  !snapshot.hasData) {
                children.add(const PgLoadingState());
              } else if (snapshot.hasError) {
                children.add(
                  PgErrorState(
                    message: errorMessage(snapshot.error),
                    onRetry: _reload,
                  ),
                );
              } else {
                final payload = snapshot.data ?? const <String, dynamic>{};
                final dealers = _maps(payload['data']);
                final employees = _maps(payload['employees']);
                final performance = _maps(payload['employee_performance']);
                final summary = payload['summary'] is Map
                    ? Map<String, dynamic>.from(payload['summary'] as Map)
                    : const <String, dynamic>{};
                final actions = payload['today_actions'] is Map
                    ? Map<String, dynamic>.from(
                        payload['today_actions'] as Map,
                      )
                    : const <String, dynamic>{};
                final overdue = _maps(actions['overdue']);
                final dueToday = _maps(actions['due_today']);
                final paidToday = _maps(actions['payments_received_today']);

                children.addAll([
                  _SummaryBlock(summary: summary),
                  const SizedBox(height: AppSpacing.md),
                  _EmployeeFilter(
                    employeeId: _employeeId,
                    employees: employees,
                    onSelect: _selectEmployee,
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  const _SectionTitle('Today\'s Action'),
                  const SizedBox(height: 8),
                  _ActionTile(
                    emoji: '🔴',
                    title: 'Overdue Commitments',
                    count: overdue.length,
                    color: AppColors.error,
                    onTap: () => _openActionList(
                      title: 'Overdue Commitments',
                      dealers: overdue,
                    ),
                  ),
                  const SizedBox(height: 8),
                  _ActionTile(
                    emoji: '🟠',
                    title: 'Commitments Due Today',
                    count: dueToday.length,
                    color: AppColors.warning,
                    onTap: () => _openActionList(
                      title: 'Commitments Due Today',
                      dealers: dueToday,
                    ),
                  ),
                  const SizedBox(height: 8),
                  _ActionTile(
                    emoji: '🟢',
                    title: 'Payments Received Today',
                    count: paidToday.length,
                    color: AppColors.success,
                    onTap: () => _openActionList(
                      title: 'Payments Received Today',
                      dealers: paidToday,
                    ),
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  _EmployeePerformanceBlock(rows: performance),
                  const SizedBox(height: AppSpacing.lg),
                  const _SectionTitle('Dealers'),
                  const SizedBox(height: 8),
                  if (dealers.isEmpty)
                    const PgEmptyState(
                      message: 'No assigned dealers in this recovery view.',
                    )
                  else
                    ...dealers.map(
                      (dealer) => Padding(
                        key: ValueKey(
                          'recovery-dealer-${dealer['dealer_id']}',
                        ),
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: _DealerRecoveryCard(
                          dealer: dealer,
                          onTap: () => _openDealer(dealer),
                        ),
                      ),
                    ),
                ]);
              }

              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: children,
              );
            },
          ),
        ),
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.title);

  final String title;

  @override
  Widget build(BuildContext context) {
    return Text(
      title,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: Theme.of(context).textTheme.titleMedium?.copyWith(
            fontWeight: FontWeight.w800,
          ),
    );
  }
}

class _EmployeeFilter extends StatelessWidget {
  const _EmployeeFilter({
    required this.employeeId,
    required this.employees,
    required this.onSelect,
  });

  final int? employeeId;
  final List<Map<String, dynamic>> employees;
  final void Function(int? employeeId) onSelect;

  @override
  Widget build(BuildContext context) {
    final matches = employees.where((row) {
      return int.tryParse('${row['employee_id'] ?? 0}') == employeeId;
    });
    final selectedEmployee = matches.isEmpty ? null : matches.first;
    final employeeLabel = selectedEmployee == null
        ? 'All Employees'
        : (selectedEmployee['employee_name']?.toString() ?? 'All Employees');

    return PopupMenuButton<int>(
      onSelected: (value) => onSelect(value == 0 ? null : value),
      itemBuilder: (context) => [
        const PopupMenuItem(value: 0, child: Text('All Employees')),
        ...employees.map((row) {
          final id = int.tryParse('${row['employee_id'] ?? 0}') ?? 0;
          return PopupMenuItem(
            value: id,
            child: Text(
              row['employee_name']?.toString() ?? '-',
              overflow: TextOverflow.ellipsis,
            ),
          );
        }),
      ],
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: AppColors.border),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          children: [
            const Icon(Icons.filter_list_rounded, size: 18),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                employeeLabel,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.labelLarge?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
              ),
            ),
            const Icon(Icons.keyboard_arrow_down_rounded),
          ],
        ),
      ),
    );
  }
}

class _SummaryBlock extends StatelessWidget {
  const _SummaryBlock({required this.summary});

  final Map<String, dynamic> summary;

  @override
  Widget build(BuildContext context) {
    final due = double.tryParse('${summary['total_current_due'] ?? 0}') ?? 0;
    return Column(
      children: [
        Container(
          width: double.infinity,
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 16),
          decoration: BoxDecoration(
            color: AppColors.primary,
            borderRadius: BorderRadius.circular(AppSpacing.radiusLg),
            boxShadow: [
              BoxShadow(
                color: AppColors.primary.withValues(alpha: 0.22),
                blurRadius: 16,
                offset: const Offset(0, 6),
              ),
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Total Current Due',
                style: Theme.of(context).textTheme.labelMedium?.copyWith(
                      color: Colors.white.withValues(alpha: 0.82),
                      fontWeight: FontWeight.w700,
                    ),
              ),
              const SizedBox(height: 6),
              FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerLeft,
                child: Text(
                  _compactInr(due),
                  maxLines: 1,
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                        color: Colors.white,
                        fontWeight: FontWeight.w800,
                        letterSpacing: -0.6,
                      ),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: _MiniStat(
                label: 'Overdue Dealers',
                value: '${summary['overdue_dealers'] ?? 0}',
                color: AppColors.error,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _MiniStat(
                label: 'Commitments Due Today',
                value: '${summary['commitments_due_today'] ?? 0}',
                color: AppColors.warning,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _MiniStat(
                label: 'Payments Received Today',
                value: '${summary['payments_received_today'] ?? 0}',
                color: AppColors.success,
              ),
            ),
          ],
        ),
      ],
    );
  }
}

class _MiniStat extends StatelessWidget {
  const _MiniStat({
    required this.label,
    required this.value,
    required this.color,
  });

  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      padding: const EdgeInsets.fromLTRB(10, 10, 10, 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
                  color: AppColors.textSecondary,
                  fontWeight: FontWeight.w700,
                  height: 1.2,
                ),
          ),
          const SizedBox(height: 6),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              value,
              maxLines: 1,
              style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    color: color,
                    fontWeight: FontWeight.w800,
                  ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ActionTile extends StatelessWidget {
  const _ActionTile({
    required this.emoji,
    required this.title,
    required this.count,
    required this.color,
    required this.onTap,
  });

  final String emoji;
  final String title;
  final int count;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      onTap: onTap,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
      child: Row(
        children: [
          Text(emoji, style: const TextStyle(fontSize: 18)),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              title,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
            ),
          ),
          Text(
            '$count',
            style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  color: color,
                  fontWeight: FontWeight.w800,
                ),
          ),
          const SizedBox(width: 4),
          Icon(
            Icons.chevron_right_rounded,
            color: AppColors.textMuted.withValues(alpha: 0.9),
          ),
        ],
      ),
    );
  }
}

class _EmployeePerformanceBlock extends StatefulWidget {
  const _EmployeePerformanceBlock({required this.rows});

  final List<Map<String, dynamic>> rows;

  @override
  State<_EmployeePerformanceBlock> createState() =>
      _EmployeePerformanceBlockState();
}

class _EmployeePerformanceBlockState extends State<_EmployeePerformanceBlock> {
  late bool _expanded = widget.rows.length <= 3;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          GestureDetector(
            behavior: HitTestBehavior.opaque,
            onTap: () => setState(() => _expanded = !_expanded),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      'Employee Performance',
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w800,
                          ),
                    ),
                  ),
                  Icon(
                    _expanded
                        ? Icons.expand_less_rounded
                        : Icons.expand_more_rounded,
                    color: AppColors.textSecondary,
                  ),
                ],
              ),
            ),
          ),
          if (_expanded) ...[
            const SizedBox(height: 8),
            if (widget.rows.isEmpty)
              const PgEmptyState(
                message: 'No employee recovery data for this filter.',
              )
            else
              ...widget.rows.map(
                (row) => Padding(
                  key: ValueKey('perf-${row['employee_id']}'),
                  padding: const EdgeInsets.only(bottom: 8),
                  child: _EmployeePerformanceCard(row: row),
                ),
              ),
          ],
        ],
      ),
    );
  }
}

class _EmployeePerformanceCard extends StatelessWidget {
  const _EmployeePerformanceCard({required this.row});

  final Map<String, dynamic> row;

  @override
  Widget build(BuildContext context) {
    final recovery =
        double.tryParse('${row['recovery_percentage'] ?? 0}') ?? 0;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            row['employee_name']?.toString() ?? 'Employee',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
          ),
          const SizedBox(height: 8),
          Text(
            'Current Due',
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
                  color: AppColors.textSecondary,
                  fontWeight: FontWeight.w700,
                ),
          ),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              _moneyLabel(row, 'current_due', 'current_due_label'),
              maxLines: 1,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: AppColors.primary,
                  ),
            ),
          ),
          const SizedBox(height: 8),
          _MetricWrap(
            items: [
              (
                'Committed',
                _moneyLabel(row, 'total_committed', 'total_committed_label')
              ),
              (
                'Received',
                _moneyLabel(row, 'total_received', 'total_received_label')
              ),
              ('Recovery', '${recovery.toStringAsFixed(1)}%'),
              ('Open Cycles', '${row['open_cycles'] ?? 0}'),
              ('Closed Cycles', '${row['closed_cycles'] ?? 0}'),
              ('Missed', '${row['missed_commitments'] ?? 0}'),
            ],
          ),
        ],
      ),
    );
  }
}

class _MetricWrap extends StatelessWidget {
  const _MetricWrap({required this.items});

  final List<(String, String)> items;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final item in items)
          ConstrainedBox(
            constraints: const BoxConstraints(minWidth: 96, maxWidth: 160),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  item.$1,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                        color: AppColors.textMuted,
                        fontWeight: FontWeight.w700,
                      ),
                ),
                Text(
                  item.$2,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}

class _DealerRecoveryCard extends StatelessWidget {
  const _DealerRecoveryCard({
    required this.dealer,
    required this.onTap,
  });

  final Map<String, dynamic> dealer;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final status = dealer['display_status']?.toString() ?? 'pending';
    final statusLabel =
        dealer['display_status_label']?.toString() ?? status.toUpperCase();
    final risk = dealer['risk']?.toString() ?? 'low';
    final riskLabel = dealer['risk_label']?.toString() ?? 'LOW RISK';
    final recovery =
        double.tryParse('${dealer['recovery_percentage'] ?? 0}') ?? 0;

    return PgCard(
      onTap: onTap,
      padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  dealer['dealer_name']?.toString() ?? '-',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                ),
              ),
              const SizedBox(width: 8),
              PgStatusBadge(label: riskLabel, tone: _statusTone(risk)),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(
                    _moneyLabel(
                      dealer,
                      'current_outstanding',
                      'current_due_label',
                    ),
                    maxLines: 1,
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.w800,
                          color: AppColors.primary,
                        ),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              PgStatusBadge(label: statusLabel, tone: _statusTone(status)),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            'Follow-ups ${dealer['follow_up_count'] ?? 0}  ·  Commitments ${dealer['commitment_count'] ?? 0}  ·  Missed ${dealer['missed_count'] ?? 0}',
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                  fontWeight: FontWeight.w600,
                ),
          ),
          const SizedBox(height: 4),
          Text(
            'Next ${_formatDate(dealer['next_follow_up_date']?.toString())}  ·  Recovery ${recovery.toStringAsFixed(1)}%',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textMuted,
                  fontWeight: FontWeight.w600,
                ),
          ),
        ],
      ),
    );
  }
}

class _FilteredDealerListPage extends StatelessWidget {
  const _FilteredDealerListPage({
    required this.auth,
    required this.title,
    required this.dealers,
    required this.onOpenDealer,
  });

  final AuthController auth;
  final String title;
  final List<Map<String, dynamic>> dealers;
  final Future<void> Function(Map<String, dynamic> dealer) onOpenDealer;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: title,
        auth: auth,
        showBack: true,
        onBack: () => Navigator.of(context).pop(),
      ),
      body: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(AppSpacing.screenPadding),
        children: [
          if (dealers.isEmpty)
            const PgEmptyState(message: 'No dealers in this list.')
          else
            ...dealers.map(
              (dealer) => Padding(
                key: ValueKey('filtered-dealer-${dealer['dealer_id']}'),
                padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: _DealerRecoveryCard(
                  dealer: dealer,
                  onTap: () => onOpenDealer(dealer),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class DirectorPaymentFollowUpHistoryScreen extends StatefulWidget {
  const DirectorPaymentFollowUpHistoryScreen({
    super.key,
    required this.auth,
    required this.dealerId,
  });

  final AuthController auth;
  final int dealerId;

  @override
  State<DirectorPaymentFollowUpHistoryScreen> createState() =>
      _DirectorPaymentFollowUpHistoryScreenState();
}

class _DirectorPaymentFollowUpHistoryScreenState
    extends State<DirectorPaymentFollowUpHistoryScreen> {
  late Future<PaymentFollowUpDetail> _future;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<PaymentFollowUpDetail> _load() async {
    final json = await _api.getPaymentFollowUp(widget.dealerId);
    return PaymentFollowUpDetail.fromJson(json);
  }

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  List<(PaymentFollowUpCycle, PaymentFollowUpEntry)> _chronological(
    PaymentFollowUpDetail detail,
  ) {
    final items = <(PaymentFollowUpCycle, PaymentFollowUpEntry)>[
      for (final cycle in detail.cycles)
        for (final entry in cycle.entries) (cycle, entry),
    ];
    items.sort((left, right) {
      final leftDate = DateTime.tryParse(left.$2.followUpDate);
      final rightDate = DateTime.tryParse(right.$2.followUpDate);
      if (leftDate == null && rightDate == null) return 0;
      if (leftDate == null) return 1;
      if (rightDate == null) return -1;
      return leftDate.compareTo(rightDate);
    });
    return items;
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
          title: 'Recovery Details',
          auth: widget.auth,
          showBack: true,
          onBack: () => smartBack(context),
        ),
        body: RefreshIndicator(
          color: AppColors.primary,
          onRefresh: _reload,
          child: FutureBuilder<PaymentFollowUpDetail>(
            future: _future,
            builder: (context, snapshot) {
              final children = <Widget>[];
              if (snapshot.connectionState == ConnectionState.waiting &&
                  !snapshot.hasData) {
                children.add(const PgLoadingState());
              } else if (snapshot.hasError) {
                children.add(
                  PgErrorState(
                    message: errorMessage(snapshot.error),
                    onRetry: _reload,
                  ),
                );
              } else {
                final detail = snapshot.data;
                if (detail == null) {
                  children.add(
                    const PgEmptyState(message: 'Dealer not found.'),
                  );
                } else {
                  final timeline = _chronological(detail);
                  final status = detail.displayStatus.toLowerCase();
                  children.addAll([
                    PgCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          detail.dealerName,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.titleLarge?.copyWith(
                                fontWeight: FontWeight.w800,
                              ),
                        ),
                        if ((detail.assignedEmployeeName ?? '').isNotEmpty) ...[
                          const SizedBox(height: 4),
                          Text(
                            detail.assignedEmployeeName!,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: Theme.of(context).textTheme.bodyMedium
                                ?.copyWith(color: AppColors.textSecondary),
                          ),
                        ],
                        const SizedBox(height: AppSpacing.md),
                        Text(
                          'Current Due',
                          style: Theme.of(context).textTheme.labelMedium
                              ?.copyWith(fontWeight: FontWeight.w700),
                        ),
                        FittedBox(
                          fit: BoxFit.scaleDown,
                          alignment: Alignment.centerLeft,
                          child: Text(
                            detail.currentOutstandingLabel,
                            maxLines: 1,
                            style: Theme.of(context)
                                .textTheme
                                .headlineSmall
                                ?.copyWith(
                                  fontWeight: FontWeight.w800,
                                  color: AppColors.primary,
                                ),
                          ),
                        ),
                        const SizedBox(height: 10),
                        Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            PgStatusBadge(
                              label: detail.currentCycleStatusLabel.isEmpty
                                  ? detail.statusLabel
                                  : detail.currentCycleStatusLabel,
                              tone: _statusTone(status),
                            ),
                            if ((detail.riskLabel ?? '').isNotEmpty)
                              PgStatusBadge(
                                label: detail.riskLabel!,
                                tone: _statusTone(
                                  detail.riskLabel!.toLowerCase().contains('high')
                                      ? 'high'
                                      : detail.riskLabel!
                                              .toLowerCase()
                                              .contains('medium')
                                          ? 'medium'
                                          : 'low',
                                ),
                              ),
                          ],
                        ),
                        const SizedBox(height: 12),
                        _MetricWrap(
                          items: [
                            ('Follow-ups', '${detail.followUpCount}'),
                            ('Commitments', '${detail.commitmentCount}'),
                            ('Missed', '${detail.missedCount}'),
                            (
                              'Received',
                              detail.totalReceivedLabel.trim().isEmpty
                                  ? '—'
                                  : detail.totalReceivedLabel
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  const _SectionTitle('Follow-up + Payment Timeline'),
                  const SizedBox(height: AppSpacing.sm),
                  if (timeline.isEmpty)
                    const PgEmptyState(
                      message: 'No follow-up history yet for this dealer.',
                      icon: Icon(Icons.history),
                    )
                  else
                    ...timeline.map(
                      (item) => Padding(
                        key: ValueKey(
                          'timeline-${item.$1.cycleNumber}-${item.$2.entryType}-${item.$2.followUpNumber}-${item.$2.followUpDate}',
                        ),
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: _TimelineCard(entry: item.$2),
                      ),
                    ),
                  ]);
                }
              }

              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: children,
              );
            },
          ),
        ),
      ),
    );
  }
}

class _TimelineCard extends StatelessWidget {
  const _TimelineCard({required this.entry});

  final PaymentFollowUpEntry entry;

  @override
  Widget build(BuildContext context) {
    final isPayment = entry.isPaymentReceived;
    final title = isPayment
        ? 'Payment Received'
        : 'Follow-up #${entry.followUpNumber ?? '—'}';
    return PgCard(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                ),
              ),
              PgStatusBadge(
                label: isPayment
                    ? 'PAYMENT'
                    : (entry.commitmentStatusLabel ?? 'FOLLOW-UP'),
                tone: isPayment
                    ? PgStatusTone.paid
                    : _statusTone(entry.commitmentStatus ?? 'pending'),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            entry.followUpAtLabel ?? _formatDate(entry.followUpDate),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                  fontWeight: FontWeight.w600,
                ),
          ),
          if (!isPayment && (entry.employeeName ?? '').trim().isNotEmpty)
            Text(
              entry.employeeName!,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          if (!isPayment && entry.remark.trim().isNotEmpty) ...[
            const SizedBox(height: 8),
            Text(
              entry.remark,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ],
          const SizedBox(height: 10),
          if (isPayment)
            _MetricWrap(
              items: [
                ('Amount', entry.paymentAmountLabel ?? entry.expectedAmountLabel ?? '—'),
                ('Date', _formatDate(entry.paymentDate ?? entry.followUpDate)),
                (
                  'Updated Current Due',
                  entry.updatedCurrentDueLabel ?? entry.outstandingLabel
                ),
              ],
            )
          else
            _MetricWrap(
              items: [
                ('Commitment Amount', entry.expectedAmountLabel ?? '—'),
                ('Commitment Date', _formatDate(entry.nextFollowUpDate)),
              ],
            ),
        ],
      ),
    );
  }
}
