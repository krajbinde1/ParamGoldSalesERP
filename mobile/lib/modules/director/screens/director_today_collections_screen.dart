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
import '../../../core/widgets/design/pg_proof_image.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/director_api.dart';

final _inr = NumberFormat.currency(
  locale: 'en_IN',
  symbol: '₹',
  decimalDigits: 0,
);

class DirectorTodayCollectionsScreen extends StatefulWidget {
  const DirectorTodayCollectionsScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<DirectorTodayCollectionsScreen> createState() =>
      _DirectorTodayCollectionsScreenState();
}

class _DirectorTodayCollectionsScreenState
    extends State<DirectorTodayCollectionsScreen> {
  late Future<Map<String, dynamic>> _future;
  String _period = 'today';
  String? _dateFrom;
  String? _dateTo;
  int? _employeeId;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<Map<String, dynamic>> _load() => _api.loadTodayCollectionDealers(
        period: _period,
        dateFrom: _dateFrom,
        dateTo: _dateTo,
        employeeId: _employeeId,
      );

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  void _applyFilters({
    required String period,
    String? dateFrom,
    String? dateTo,
    int? employeeId,
  }) {
    setState(() {
      _period = period;
      _dateFrom = dateFrom;
      _dateTo = dateTo;
      _employeeId = employeeId;
      _future = _load();
    });
  }

  Future<void> _resetFilters() async {
    _applyFilters(period: 'today', employeeId: null);
  }

  Future<void> _selectPeriod(String period) async {
    if (period == 'custom') {
      await _openCustomDate();
      return;
    }
    _applyFilters(period: period, employeeId: _employeeId);
  }

  Future<void> _openCustomDate() async {
    final now = DateTime.now();
    var from = _dateFrom != null
        ? DateTime.tryParse(_dateFrom!) ?? now
        : now;
    var to = _dateTo != null ? DateTime.tryParse(_dateTo!) ?? now : now;

    final applied = await showDialog<bool>(
      context: context,
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setDialogState) {
            Future<void> pick({required bool isFrom}) async {
              final selected = await showDatePicker(
                context: context,
                initialDate: isFrom ? from : to,
                firstDate: DateTime(2024),
                lastDate: now,
              );
              if (selected == null) return;
              setDialogState(() {
                if (isFrom) {
                  from = selected;
                  if (to.isBefore(from)) to = from;
                } else {
                  to = selected;
                  if (to.isBefore(from)) from = to;
                }
              });
            }

            return AlertDialog(
              title: const Text('Custom Date'),
              content: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: const Text('From Date'),
                    subtitle: Text(DateFormat('d MMM yyyy').format(from)),
                    trailing: const Icon(Icons.calendar_today_outlined),
                    onTap: () => pick(isFrom: true),
                  ),
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: const Text('To Date'),
                    subtitle: Text(DateFormat('d MMM yyyy').format(to)),
                    trailing: const Icon(Icons.calendar_today_outlined),
                    onTap: () => pick(isFrom: false),
                  ),
                ],
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(context),
                  child: const Text('Cancel'),
                ),
                TextButton(
                  onPressed: () => Navigator.pop(context, false),
                  child: const Text('Reset'),
                ),
                FilledButton(
                  onPressed: () => Navigator.pop(context, true),
                  child: const Text('Apply'),
                ),
              ],
            );
          },
        );
      },
    );

    if (!mounted) return;
    if (applied == true) {
      _applyFilters(
        period: 'custom',
        dateFrom: DateFormat('yyyy-MM-dd').format(from),
        dateTo: DateFormat('yyyy-MM-dd').format(to),
        employeeId: _employeeId,
      );
      return;
    }
    if (applied == false) {
      await _resetFilters();
    }
  }

  Future<void> _openDealer(Map<String, dynamic> dealer) async {
    final dealerId = int.tryParse('${dealer['dealer_id'] ?? 0}') ?? 0;
    if (dealerId <= 0) return;
    await context.push(
      '/director/today-collections/$dealerId',
      extra: {
        'dealer': dealer,
        'period': _period,
        'date_from': _dateFrom,
        'date_to': _dateTo,
        'employee_id': _employeeId,
      },
    );
    if (!mounted) return;
    await _reload();
  }

  String get _title {
    return switch (_period) {
      'week' => "This Week's Collection – Dealers",
      'month' => "This Month's Collection – Dealers",
      'custom' => 'Custom Date – Dealers',
      _ => "Today's Collection – Dealers",
    };
  }

  String _amountLabel() {
    return switch (_period) {
      'week' => "This Week's Collection",
      'month' => "This Month's Collection",
      'custom' => 'Collection',
      _ => "Today's Collection",
    };
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
          title: _title,
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
              final dealers = (snapshot.data?['dealers'] as List?)
                      ?.whereType<Map>()
                      .map((item) => Map<String, dynamic>.from(item))
                      .toList() ??
                  const <Map<String, dynamic>>[];
              final employees = (snapshot.data?['employees'] as List?)
                      ?.whereType<Map>()
                      .map((item) => Map<String, dynamic>.from(item))
                      .toList() ??
                  const <Map<String, dynamic>>[];
              final total = double.tryParse(
                    '${snapshot.data?['total_collection'] ?? 0}',
                  ) ??
                  0;
              final dealersCount = int.tryParse(
                    '${snapshot.data?['dealers_count'] ?? dealers.length}',
                  ) ??
                  dealers.length;
              final entriesCount = int.tryParse(
                    '${snapshot.data?['entries_count'] ?? 0}',
                  ) ??
                  0;

              final header = [
                _FilterBar(
                  period: _period,
                  employeeId: _employeeId,
                  employees: employees,
                  onSelectPeriod: _selectPeriod,
                  onSelectEmployee: (id) => _applyFilters(
                    period: _period,
                    dateFrom: _dateFrom,
                    dateTo: _dateTo,
                    employeeId: id,
                  ),
                ),
                if (snapshot.hasData) ...[
                  const SizedBox(height: AppSpacing.sm),
                  PgCard(
                    margin: const EdgeInsets.only(bottom: AppSpacing.md),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Total Collection: ${_inr.format(total)}',
                          style: Theme.of(context)
                              .textTheme
                              .titleSmall
                              ?.copyWith(fontWeight: FontWeight.w800),
                        ),
                        Text(
                          'Dealers: $dealersCount',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        Text(
                          'Entries: $entriesCount',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ],
                    ),
                  ),
                ],
              ];

              if (snapshot.connectionState == ConnectionState.waiting &&
                  !snapshot.hasData) {
                return ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(AppSpacing.screenPadding),
                  children: [
                    ...header,
                    const PgLoadingState(),
                  ],
                );
              }
              if (snapshot.hasError) {
                return ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(AppSpacing.screenPadding),
                  children: [
                    ...header,
                    PgErrorState(
                      message: 'Unable to load collections',
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

              if (dealers.isEmpty) {
                return ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(AppSpacing.screenPadding),
                  children: [
                    ...header,
                    const PgEmptyState(
                      message: 'No collections recorded for the selected filters',
                    ),
                  ],
                );
              }

              return ListView.builder(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                itemCount: dealers.length + 1,
                itemBuilder: (context, index) {
                  if (index == 0) {
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: header,
                    );
                  }
                  final dealer = dealers[index - 1];
                  final code = dealer['dealer_code']?.toString().trim() ?? '';
                  final village = dealer['village']?.toString().trim() ?? '';
                  final locationLine = [
                    if (code.isNotEmpty) code,
                    if (village.isNotEmpty) village,
                  ].join(' • ');
                  final employee =
                      dealer['employee_name']?.toString().trim().isNotEmpty ==
                              true
                          ? dealer['employee_name'].toString()
                          : (dealer['assigned_employee_name']?.toString() ??
                              '-');
                  final amount =
                      double.tryParse('${dealer['total_amount'] ?? 0}') ?? 0;
                  final entries =
                      int.tryParse('${dealer['entries_count'] ?? 0}') ?? 0;

                  return PgCard(
                    onTap: () => _openDealer(dealer),
                    margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          dealer['dealer_name']?.toString() ?? '-',
                          style: Theme.of(context)
                              .textTheme
                              .titleSmall
                              ?.copyWith(fontWeight: FontWeight.w800),
                        ),
                        if (locationLine.isNotEmpty)
                          Text(
                            locationLine,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        const SizedBox(height: 4),
                        Text(
                          employee,
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        const SizedBox(height: 8),
                        Text(
                          '${_amountLabel()}: ${_inr.format(amount)}',
                          style: Theme.of(context)
                              .textTheme
                              .titleSmall
                              ?.copyWith(
                                color: AppColors.primary,
                                fontWeight: FontWeight.w800,
                              ),
                        ),
                        Text(
                          '$entries ${entries == 1 ? 'Entry' : 'Entries'}',
                          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                color: AppColors.textSecondary,
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
      ),
    );
  }
}

class _FilterBar extends StatelessWidget {
  const _FilterBar({
    required this.period,
    required this.employeeId,
    required this.employees,
    required this.onSelectPeriod,
    required this.onSelectEmployee,
  });

  final String period;
  final int? employeeId;
  final List<Map<String, dynamic>> employees;
  final Future<void> Function(String period) onSelectPeriod;
  final void Function(int? employeeId) onSelectEmployee;

  @override
  Widget build(BuildContext context) {
    final matches = employees.where((row) {
      return int.tryParse('${row['employee_id'] ?? 0}') == employeeId;
    });
    final selectedEmployee = matches.isEmpty ? null : matches.first;
    final employeeLabel = selectedEmployee == null
        ? 'All Employees'
        : (selectedEmployee['employee_name']?.toString() ?? 'All Employees');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 4,
          children: [
            _PeriodChip(
              label: 'Today',
              selected: period == 'today',
              onTap: () => onSelectPeriod('today'),
            ),
            _PeriodChip(
              label: 'This Week',
              selected: period == 'week',
              onTap: () => onSelectPeriod('week'),
            ),
            _PeriodChip(
              label: 'This Month',
              selected: period == 'month',
              onTap: () => onSelectPeriod('month'),
            ),
            _PeriodChip(
              label: 'Custom Date',
              selected: period == 'custom',
              onTap: () => onSelectPeriod('custom'),
            ),
          ],
        ),
        const SizedBox(height: 8),
        PopupMenuButton<int>(
          onSelected: (value) =>
              onSelectEmployee(value == 0 ? null : value),
          itemBuilder: (context) => [
            const PopupMenuItem(value: 0, child: Text('All Employees')),
            ...employees.map((row) {
              final id = int.tryParse('${row['employee_id'] ?? 0}') ?? 0;
              return PopupMenuItem(
                value: id,
                child: Text(row['employee_name']?.toString() ?? '-'),
              );
            }),
          ],
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(
              border: Border.all(color: AppColors.border),
              borderRadius: BorderRadius.circular(999),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Flexible(
                  child: Text(
                    '$employeeLabel ▼',
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.labelLarge?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _PeriodChip extends StatelessWidget {
  const _PeriodChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ChoiceChip(
      label: Text(label),
      selected: selected,
      onSelected: (_) => onTap(),
      selectedColor: AppColors.primary.withValues(alpha: 0.18),
      labelStyle: TextStyle(
        fontWeight: FontWeight.w700,
        color: selected ? AppColors.primary : AppColors.textSecondary,
        fontSize: 12,
      ),
    );
  }
}

class DirectorTodayCollectionDetailsScreen extends StatefulWidget {
  const DirectorTodayCollectionDetailsScreen({
    super.key,
    required this.auth,
    required this.dealerId,
    this.dealer,
    this.period = 'today',
    this.dateFrom,
    this.dateTo,
    this.employeeId,
  });

  final AuthController auth;
  final int dealerId;
  final Map<String, dynamic>? dealer;
  final String period;
  final String? dateFrom;
  final String? dateTo;
  final int? employeeId;

  @override
  State<DirectorTodayCollectionDetailsScreen> createState() =>
      _DirectorTodayCollectionDetailsScreenState();
}

class _DirectorTodayCollectionDetailsScreenState
    extends State<DirectorTodayCollectionDetailsScreen> {
  late Future<Map<String, dynamic>> _future;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<Map<String, dynamic>> _load() => _api.listTodayDealerCollections(
        dealerId: widget.dealerId,
        period: widget.period,
        dateFrom: widget.dateFrom,
        dateTo: widget.dateTo,
        employeeId: widget.employeeId,
      );

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  String _formatTime(Object? value) {
    if (value == null) return '—';
    final raw = value.toString().trim();
    if (raw.isEmpty) return '—';
    final parsed = DateTime.tryParse(raw) ??
        DateTime.tryParse('1970-01-01 $raw');
    if (parsed == null) return raw;
    return DateFormat('hh:mm a').format(parsed.toLocal());
  }

  String _formatDate(Object? value) {
    final raw = value?.toString().trim() ?? '';
    if (raw.isEmpty) return '—';
    final parsed = DateTime.tryParse(raw);
    if (parsed == null) return raw;
    return DateFormat('d MMM yyyy').format(parsed);
  }

  PgStatusTone _statusTone(String status) {
    return switch (status.toLowerCase()) {
      'received' => PgStatusTone.paid,
      'not_received' => PgStatusTone.rejected,
      _ => PgStatusTone.pending,
    };
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
          title: 'Collection Details',
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
                      message: 'Unable to load collection details',
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
              final dealer = Map<String, dynamic>.from(
                body['dealer'] as Map? ?? widget.dealer ?? const {},
              );
              final entries = (body['data'] as List?)
                      ?.whereType<Map>()
                      .map((item) => Map<String, dynamic>.from(item))
                      .toList() ??
                  const <Map<String, dynamic>>[];
              final dealerName = dealer['dealer_name']?.toString() ??
                  widget.dealer?['dealer_name']?.toString();

              if (entries.isEmpty) {
                return ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  children: [
                    SizedBox(height: MediaQuery.sizeOf(context).height * 0.25),
                    PgEmptyState(
                      message: dealerName == null
                          ? 'No collection entries for this dealer'
                          : 'No collection entries for $dealerName',
                    ),
                  ],
                );
              }

              return ListView.builder(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                itemCount: entries.length + (dealerName == null ? 0 : 1),
                itemBuilder: (context, index) {
                  if (dealerName != null && index == 0) {
                    final code = dealer['dealer_code']?.toString().trim() ?? '';
                    final village = dealer['village']?.toString().trim() ?? '';
                    final locationLine = [
                      if (code.isNotEmpty) code,
                      if (village.isNotEmpty) village,
                    ].join(' • ');
                    return Padding(
                      padding: const EdgeInsets.only(bottom: AppSpacing.md),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            dealerName,
                            style: Theme.of(context)
                                .textTheme
                                .titleMedium
                                ?.copyWith(fontWeight: FontWeight.w800),
                          ),
                          if (locationLine.isNotEmpty)
                            Text(
                              locationLine,
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                        ],
                      ),
                    );
                  }

                  final entry = entries[dealerName == null ? index : index - 1];
                  final amount =
                      double.tryParse('${entry['amount'] ?? 0}') ?? 0;
                  final time = _formatTime(
                    entry['collected_at'] ?? entry['collection_time'],
                  );
                  final date = _formatDate(entry['collection_date']);
                  final employee =
                      entry['employee_name']?.toString().trim() ?? '-';
                  final paymentMode =
                      entry['payment_mode']?.toString().trim() ?? '';
                  final remark = entry['remarks']?.toString().trim() ?? '';
                  final status = entry['status']?.toString() ?? '';
                  final statusLabel =
                      entry['status_label']?.toString() ?? status;
                  final photoUrl = (entry['supporting_image_url'] ??
                          entry['photo_url'])
                      ?.toString();

                  return PgCard(
                    margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _inr.format(amount),
                          style: Theme.of(context)
                              .textTheme
                              .titleMedium
                              ?.copyWith(fontWeight: FontWeight.w800),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          '$date • $time',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        Text(
                          'Collected by $employee',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        if (paymentMode.isNotEmpty)
                          Text(
                            paymentMode,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        if (remark.isNotEmpty)
                          Text(
                            remark,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        if (statusLabel.isNotEmpty) ...[
                          const SizedBox(height: 8),
                          PgStatusBadge(
                            label: 'Status: $statusLabel',
                            tone: _statusTone(status),
                          ),
                        ],
                        if ((photoUrl ?? '').trim().isNotEmpty) ...[
                          const SizedBox(height: 10),
                          PgProofImage(url: photoUrl),
                        ],
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
