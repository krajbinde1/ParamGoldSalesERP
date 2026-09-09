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
import '../../../core/widgets/design/pg_metric_card.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/company_transport_api.dart';

class CompanyTransportLedgerScreen extends StatefulWidget {
  const CompanyTransportLedgerScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<CompanyTransportLedgerScreen> createState() =>
      _CompanyTransportLedgerScreenState();
}

class _CompanyTransportLedgerScreenState
    extends State<CompanyTransportLedgerScreen> {
  late Future<Map<String, dynamic>> _future;
  DateTime? _from;
  DateTime? _to;
  final _vehicleCtrl = TextEditingController();
  final _orderCtrl = TextEditingController();
  String? _expenseType;

  static final _inr = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 2,
  );

  CompanyTransportApi get _api => CompanyTransportApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  @override
  void dispose() {
    _vehicleCtrl.dispose();
    _orderCtrl.dispose();
    super.dispose();
  }

  String _ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<Map<String, dynamic>> _load() {
    return _api.ledger(
      from: _from == null ? null : _ymd(_from!),
      to: _to == null ? null : _ymd(_to!),
      vehicleNo: _vehicleCtrl.text.trim(),
      orderNo: _orderCtrl.text.trim(),
      expenseType: _expenseType,
    );
  }

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _pickDate({required bool from}) async {
    final initial = (from ? _from : _to) ?? DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(2024),
      lastDate: DateTime.now().add(const Duration(days: 365)),
    );
    if (picked == null) return;
    setState(() {
      if (from) {
        _from = picked;
      } else {
        _to = picked;
      }
    });
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    final canAddExpense =
        widget.auth.permissions.canCreateCompanyTransportExpense;

    return PopScope(
      canPop: context.canPop(),
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        smartBack(context);
      },
      child: Scaffold(
        appBar: RoleAppBar(
          title: 'Company Transport',
          auth: widget.auth,
          showBack: true,
          onBack: () => smartBack(context),
        ),
        floatingActionButton: canAddExpense
            ? FloatingActionButton.extended(
                onPressed: () async {
                  final added = await context.push<bool>(
                    '/production/company-transport/expense',
                  );
                  if (added == true) await _reload();
                },
                icon: const Icon(Icons.add),
                label: const Text('Add Expense'),
              )
            : null,
        body: RefreshIndicator(
          onRefresh: _reload,
          child: FutureBuilder<Map<String, dynamic>>(
            future: _future,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const Center(child: CircularProgressIndicator());
              }
              if (snapshot.hasError) {
                return ListView(
                  children: [
                    PgEmptyState(
                      message: errorMessage(snapshot.error!),
                    ),
                  ],
                );
              }
              final data = snapshot.data ?? const {};
              final summary = Map<String, dynamic>.from(
                data['summary'] as Map? ?? const {},
              );
              final lookups = Map<String, dynamic>.from(
                data['lookups'] as Map? ?? const {},
              );
              final expenseTypes = Map<String, dynamic>.from(
                lookups['expense_types'] as Map? ?? const {},
              );
              final entries = (data['entries'] as List? ?? const [])
                  .whereType<Map>()
                  .map((row) => Map<String, dynamic>.from(row))
                  .toList();

              return ListView(
                padding: EdgeInsets.fromLTRB(
                  AppSpacing.screenPadding,
                  AppSpacing.screenPadding,
                  AppSpacing.screenPadding,
                  canAddExpense ? 96 : AppSpacing.screenPadding,
                ),
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: PgMetricCard(
                          title: 'Collected',
                          value: summary['total_collected_label']?.toString() ??
                              _inr.format(
                                double.tryParse(
                                      '${summary['total_collected'] ?? 0}',
                                    ) ??
                                    0,
                              ),
                          icon: const Icon(Icons.south_west),
                          gradient: const [Color(0xFF059669), Color(0xFF10B981)],
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: PgMetricCard(
                          title: 'Expense',
                          value: summary['total_expense_label']?.toString() ??
                              _inr.format(
                                double.tryParse(
                                      '${summary['total_expense'] ?? 0}',
                                    ) ??
                                    0,
                              ),
                          icon: const Icon(Icons.north_east),
                          gradient: const [Color(0xFFDC2626), Color(0xFFF97316)],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  PgMetricCard(
                    title: 'Current Balance',
                    value: summary['current_balance_label']?.toString() ??
                        _inr.format(
                          double.tryParse(
                                '${summary['current_balance'] ?? 0}',
                              ) ??
                              0,
                        ),
                    icon: const Icon(Icons.account_balance_wallet_outlined),
                    gradient: const [Color(0xFF2563EB), Color(0xFF0F766E)],
                    expand: false,
                  ),
                  const SizedBox(height: AppSpacing.md),
                  PgCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Filters',
                          style: Theme.of(context).textTheme.titleSmall,
                        ),
                        const SizedBox(height: 8),
                        Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            ActionChip(
                              label: Text(
                                _from == null
                                    ? 'From Date'
                                    : DateFormat('dd MMM yyyy').format(_from!),
                              ),
                              onPressed: () => _pickDate(from: true),
                            ),
                            ActionChip(
                              label: Text(
                                _to == null
                                    ? 'To Date'
                                    : DateFormat('dd MMM yyyy').format(_to!),
                              ),
                              onPressed: () => _pickDate(from: false),
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        TextField(
                          controller: _vehicleCtrl,
                          decoration: const InputDecoration(
                            labelText: 'Vehicle No.',
                            isDense: true,
                          ),
                          onSubmitted: (_) => _reload(),
                        ),
                        const SizedBox(height: 8),
                        TextField(
                          controller: _orderCtrl,
                          decoration: const InputDecoration(
                            labelText: 'Order No.',
                            isDense: true,
                          ),
                          onSubmitted: (_) => _reload(),
                        ),
                        const SizedBox(height: 8),
                        DropdownButtonFormField<String?>(
                          value: _expenseType,
                          decoration: const InputDecoration(
                            labelText: 'Expense Type',
                            isDense: true,
                          ),
                          items: [
                            const DropdownMenuItem<String?>(
                              value: null,
                              child: Text('All'),
                            ),
                            ...expenseTypes.entries.map(
                              (e) => DropdownMenuItem(
                                value: e.key,
                                child: Text('${e.value}'),
                              ),
                            ),
                          ],
                          onChanged: (value) {
                            setState(() => _expenseType = value);
                            _reload();
                          },
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: AppSpacing.md),
                  Text(
                    'Transport Ledger',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 8),
                  if (entries.isEmpty)
                    const PgEmptyState(
                      message:
                          'Transport credits appear after a sales order with Company Transport or Transport Charges Extra is dispatched.',
                    )
                  else
                    ...entries.map((entry) => _LedgerTile(entry: entry)),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

class _LedgerTile extends StatelessWidget {
  const _LedgerTile({required this.entry});

  final Map<String, dynamic> entry;

  @override
  Widget build(BuildContext context) {
    final isExpense = entry['is_expense'] == true;
    final debit = '${entry['debit_label'] ?? ''}';
    final credit = '${entry['credit_label'] ?? ''}';
    final id = int.tryParse('${entry['id']}') ?? 0;

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: PgCard(
        onTap: isExpense && id > 0
            ? () => context.push('/production/company-transport/entries/$id')
            : null,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    '${entry['particulars'] ?? ''}',
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                ),
                PgStatusBadge(
                  label: '${entry['entry_kind_label'] ?? ''}',
                  tone: entry['entry_kind'] == 'credit'
                      ? PgStatusTone.approved
                      : PgStatusTone.rejected,
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              [
                '${entry['transaction_date_label'] ?? entry['transaction_date'] ?? ''}',
                if (entry['is_expense'] != true)
                  '${entry['order_no'] ?? '—'}',
                if ('${entry['transport_type_label'] ?? ''}'.trim().isNotEmpty)
                  '${entry['transport_type_label']}',
                '${entry['vehicle_number'] ?? '—'}',
              ].join(' • '),
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
            ),
            const SizedBox(height: 6),
            Row(
              children: [
                if (debit.isNotEmpty)
                  Text(
                    'Dr $debit',
                    style: const TextStyle(
                      color: AppColors.error,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                if (credit.isNotEmpty)
                  Text(
                    'Cr $credit',
                    style: const TextStyle(
                      color: AppColors.success,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                const Spacer(),
                Text(
                  '${entry['running_balance_label'] ?? ''}',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
