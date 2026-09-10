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

PgStatusTone _followUpTone(String status) => switch (status) {
      'overdue' => PgStatusTone.rejected,
      'due_today' => PgStatusTone.pending,
      'upcoming' => PgStatusTone.info,
      'closed' => PgStatusTone.paid,
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
          title: 'Payment Follow-up Status',
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
                      onRetry: _reload,
                    ),
                  ],
                );
              }

              final payload = snapshot.data ?? const <String, dynamic>{};
              final dealers = (payload['data'] as List? ?? const [])
                  .whereType<Map>()
                  .map((item) => Map<String, dynamic>.from(item))
                  .toList();
              final employees = (payload['employees'] as List? ?? const [])
                  .whereType<Map>()
                  .map((item) => Map<String, dynamic>.from(item))
                  .toList();

              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  _EmployeeFilter(
                    employeeId: _employeeId,
                    employees: employees,
                    onSelect: _selectEmployee,
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  if (_employeeId == null)
                    const PgEmptyState(
                      message:
                          'Select an employee to view assigned dealer follow-ups.',
                      icon: Icon(Icons.person_search_outlined),
                    )
                  else if (dealers.isEmpty)
                    const PgEmptyState(
                      message: 'No dealers assigned to this employee.',
                    )
                  else
                    ...dealers.map(
                      (dealer) => Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: _DealerFollowUpCard(
                          dealer: dealer,
                          onTap: () => _openDealer(dealer),
                        ),
                      ),
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
        ? 'Select Employee'
        : (selectedEmployee['employee_name']?.toString() ?? 'Select Employee');

    return PopupMenuButton<int>(
      onSelected: (value) => onSelect(value == 0 ? null : value),
      itemBuilder: (context) => [
        const PopupMenuItem(value: 0, child: Text('Select Employee')),
        ...employees.map((row) {
          final id = int.tryParse('${row['employee_id'] ?? 0}') ?? 0;
          return PopupMenuItem(
            value: id,
            child: Text(row['employee_name']?.toString() ?? '-'),
          );
        }),
      ],
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
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

class _DealerFollowUpCard extends StatelessWidget {
  const _DealerFollowUpCard({
    required this.dealer,
    required this.onTap,
  });

  final Map<String, dynamic> dealer;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final outstanding =
        double.tryParse('${dealer['current_outstanding'] ?? 0}') ?? 0;
    final outstandingLabel =
        dealer['current_outstanding_label']?.toString() ?? _inr.format(outstanding);
    final status = dealer['status']?.toString() ?? 'no_follow_up';
    final statusLabel = dealer['status_label']?.toString() ?? status;

    return PgCard(
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  dealer['dealer_name']?.toString() ?? '-',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                ),
              ),
              PgStatusBadge(
                label: statusLabel,
                tone: _followUpTone(status),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            outstandingLabel,
            style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  color: AppColors.error,
                  fontWeight: FontWeight.w800,
                ),
          ),
          const SizedBox(height: 8),
          Text(
            'Latest follow-up: ${_formatDate(dealer['last_follow_up_date']?.toString())}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          Text(
            'Next follow-up: ${_formatDate(dealer['next_follow_up_date']?.toString())}',
            style: Theme.of(context).textTheme.bodySmall,
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

  List<PaymentFollowUpEntry> _chronological(PaymentFollowUpDetail detail) {
    final entries = [
      for (final cycle in detail.cycles) ...cycle.entries,
    ];
    entries.sort((left, right) {
      final leftDate = DateTime.tryParse(left.followUpDate);
      final rightDate = DateTime.tryParse(right.followUpDate);
      if (leftDate == null && rightDate == null) return 0;
      if (leftDate == null) return 1;
      if (rightDate == null) return -1;
      return leftDate.compareTo(rightDate);
    });
    return entries;
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
          title: 'Payment Follow-up History',
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
                      onRetry: _reload,
                    ),
                  ],
                );
              }

              final detail = snapshot.data;
              if (detail == null) {
                return const PgEmptyState(message: 'Dealer not found.');
              }

              final timeline = _chronological(detail);

              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  PgCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          detail.dealerName,
                          style: Theme.of(context).textTheme.titleLarge?.copyWith(
                                fontWeight: FontWeight.w800,
                              ),
                        ),
                        if ((detail.village ?? '').isNotEmpty) ...[
                          const SizedBox(height: 4),
                          Text(
                            detail.village!,
                            style: Theme.of(context).textTheme.bodyMedium
                                ?.copyWith(color: AppColors.textSecondary),
                          ),
                        ],
                        const SizedBox(height: AppSpacing.md),
                        Text(
                          'Current Outstanding',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        Text(
                          detail.currentOutstandingLabel,
                          style: Theme.of(context)
                              .textTheme
                              .headlineSmall
                              ?.copyWith(fontWeight: FontWeight.w800),
                        ),
                        const SizedBox(height: AppSpacing.sm),
                        PgStatusBadge(
                          label: detail.statusLabel,
                          tone: PgStatusTone.info,
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  Text(
                    'Follow-up History',
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  if (timeline.isEmpty)
                    const PgEmptyState(
                      message: 'No follow-up history yet for this dealer.',
                      icon: Icon(Icons.history),
                    )
                  else
                    ...timeline.map(_historyCard),
                ],
              );
            },
          ),
        ),
      ),
    );
  }

  Widget _historyCard(PaymentFollowUpEntry entry) {
    final statusLabel =
        entry.isPaymentReceived ? 'Payment Received' : 'Follow-up';
    final commitmentDate = entry.isPaymentReceived
        ? entry.followUpDate
        : entry.nextFollowUpDate;

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: PgCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    _formatDate(entry.followUpDate),
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                ),
                PgStatusBadge(
                  label: statusLabel,
                  tone: entry.isPaymentReceived
                      ? PgStatusTone.paid
                      : PgStatusTone.info,
                ),
              ],
            ),
            const SizedBox(height: 8),
            _HistoryRow(
              label: 'Employee',
              value: (entry.employeeName ?? '').trim().isEmpty
                  ? '—'
                  : entry.employeeName!,
            ),
            _HistoryRow(
              label: 'Commitment / Payment date',
              value: _formatDate(commitmentDate),
            ),
            _HistoryRow(
              label: 'Commitment amount',
              value: (entry.expectedAmountLabel ?? '').trim().isEmpty
                  ? '—'
                  : entry.expectedAmountLabel!,
            ),
            _HistoryRow(
              label: 'Remarks',
              value: entry.remark.trim().isEmpty ? '—' : entry.remark,
            ),
          ],
        ),
      ),
    );
  }
}

class _HistoryRow extends StatelessWidget {
  const _HistoryRow({required this.label, required this.value});

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
            width: 148,
            child: Text(
              label,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
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
