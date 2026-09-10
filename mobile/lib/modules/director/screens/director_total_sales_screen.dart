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

String _rangeLabel(String? from, String? to) {
  if ((from == null || from.isEmpty) && (to == null || to.isEmpty)) {
    return 'This Year';
  }
  if (from == to || to == null || to.isEmpty) {
    return _formatDate(from);
  }
  return '${_formatDate(from)} – ${_formatDate(to)}';
}

class DirectorTotalSalesScreen extends StatefulWidget {
  const DirectorTotalSalesScreen({
    super.key,
    required this.auth,
    this.period,
    this.dateFrom,
    this.dateTo,
  });

  final AuthController auth;
  final String? period;
  final String? dateFrom;
  final String? dateTo;

  @override
  State<DirectorTotalSalesScreen> createState() =>
      _DirectorTotalSalesScreenState();
}

class _DirectorTotalSalesScreenState extends State<DirectorTotalSalesScreen> {
  late Future<Map<String, dynamic>> _future;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<Map<String, dynamic>> _load() => _api.listLedgerSales(
        period: widget.period ?? 'year',
        dateFrom: widget.dateFrom,
        dateTo: widget.dateTo,
      );

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _openDealer(Map<String, dynamic> dealer) async {
    final dealerId = int.tryParse('${dealer['dealer_id'] ?? 0}') ?? 0;
    if (dealerId <= 0) return;
    final period = widget.period ?? 'year';
    final from = widget.dateFrom ?? '';
    final to = widget.dateTo ?? '';
    await context.push(
      '/director/total-sales/$dealerId?period=$period&from=$from&to=$to',
    );
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
          title: 'Total Sales',
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
              final total =
                  double.tryParse('${payload['total_sales'] ?? 0}') ?? 0;
              final from = payload['start_date']?.toString() ?? widget.dateFrom;
              final to = payload['end_date']?.toString() ?? widget.dateTo;

              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  PgCard(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _rangeLabel(from, to),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.labelLarge
                              ?.copyWith(
                                color: AppColors.textSecondary,
                                fontWeight: FontWeight.w700,
                              ),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          'Total Sales',
                          style: Theme.of(context).textTheme.labelMedium
                              ?.copyWith(fontWeight: FontWeight.w700),
                        ),
                        FittedBox(
                          fit: BoxFit.scaleDown,
                          alignment: Alignment.centerLeft,
                          child: Text(
                            payload['total_sales_label']?.toString() ??
                                _inr.format(total),
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
                      ],
                    ),
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  Text(
                    'Dealers',
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  if (dealers.isEmpty)
                    const PgEmptyState(
                      message: 'No ledger sales in this period.',
                    )
                  else
                    ...dealers.map(
                      (dealer) => Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: PgCard(
                          onTap: () => _openDealer(dealer),
                          child: Row(
                            children: [
                              Expanded(
                                child: Text(
                                  dealer['dealer_name']?.toString() ?? '-',
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                  style: Theme.of(context)
                                      .textTheme
                                      .titleSmall
                                      ?.copyWith(fontWeight: FontWeight.w800),
                                ),
                              ),
                              const SizedBox(width: 12),
                              Flexible(
                                child: FittedBox(
                                  fit: BoxFit.scaleDown,
                                  alignment: Alignment.centerRight,
                                  child: Text(
                                    dealer['sales_amount_label']?.toString() ??
                                        _inr.format(
                                          double.tryParse(
                                                '${dealer['sales_amount'] ?? 0}',
                                              ) ??
                                              0,
                                        ),
                                    maxLines: 1,
                                    style: Theme.of(context)
                                        .textTheme
                                        .titleSmall
                                        ?.copyWith(
                                          fontWeight: FontWeight.w800,
                                          color: AppColors.primary,
                                        ),
                                  ),
                                ),
                              ),
                            ],
                          ),
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

class DirectorDealerSalesDetailScreen extends StatefulWidget {
  const DirectorDealerSalesDetailScreen({
    super.key,
    required this.auth,
    required this.dealerId,
    this.period,
    this.dateFrom,
    this.dateTo,
  });

  final AuthController auth;
  final int dealerId;
  final String? period;
  final String? dateFrom;
  final String? dateTo;

  @override
  State<DirectorDealerSalesDetailScreen> createState() =>
      _DirectorDealerSalesDetailScreenState();
}

class _DirectorDealerSalesDetailScreenState
    extends State<DirectorDealerSalesDetailScreen> {
  late Future<Map<String, dynamic>> _future;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<Map<String, dynamic>> _load() => _api.getDealerLedgerSales(
        dealerId: widget.dealerId,
        period: widget.period ?? 'year',
        dateFrom: widget.dateFrom,
        dateTo: widget.dateTo,
      );

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
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
          title: 'Dealer Sales',
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
              final dealer = payload['dealer'] is Map
                  ? Map<String, dynamic>.from(payload['dealer'] as Map)
                  : const <String, dynamic>{};
              final entries = (payload['data'] as List? ?? const [])
                  .whereType<Map>()
                  .map((item) => Map<String, dynamic>.from(item))
                  .toList();
              final total =
                  double.tryParse('${payload['total_sales'] ?? 0}') ?? 0;
              final from = payload['start_date']?.toString() ?? widget.dateFrom;
              final to = payload['end_date']?.toString() ?? widget.dateTo;

              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  PgCard(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          dealer['dealer_name']?.toString() ?? 'Dealer',
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.titleLarge
                              ?.copyWith(fontWeight: FontWeight.w800),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          _rangeLabel(from, to),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.bodySmall
                              ?.copyWith(
                                color: AppColors.textSecondary,
                                fontWeight: FontWeight.w600,
                              ),
                        ),
                        const SizedBox(height: 12),
                        Text(
                          'Total Sales',
                          style: Theme.of(context).textTheme.labelMedium
                              ?.copyWith(fontWeight: FontWeight.w700),
                        ),
                        FittedBox(
                          fit: BoxFit.scaleDown,
                          alignment: Alignment.centerLeft,
                          child: Text(
                            payload['total_sales_label']?.toString() ??
                                _inr.format(total),
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
                      ],
                    ),
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  Text(
                    'Sales Entries',
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  if (entries.isEmpty)
                    const PgEmptyState(
                      message: 'No debit sales entries in this period.',
                    )
                  else
                    ...entries.map(
                      (entry) => Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: PgCard(
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      _formatDate(
                                        entry['entry_date']?.toString(),
                                      ),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: Theme.of(context)
                                          .textTheme
                                          .titleSmall
                                          ?.copyWith(
                                            fontWeight: FontWeight.w800,
                                          ),
                                    ),
                                    const SizedBox(height: 4),
                                    Text(
                                      [
                                        if ((entry['voucher_type']
                                                    ?.toString() ??
                                                '')
                                            .trim()
                                            .isNotEmpty)
                                          entry['voucher_type'],
                                        if ((entry['voucher_no']?.toString() ??
                                                '')
                                            .trim()
                                            .isNotEmpty)
                                          entry['voucher_no'],
                                      ].join(' · '),
                                      maxLines: 2,
                                      overflow: TextOverflow.ellipsis,
                                      style: Theme.of(context)
                                          .textTheme
                                          .bodySmall
                                          ?.copyWith(
                                            color: AppColors.textSecondary,
                                            fontWeight: FontWeight.w600,
                                          ),
                                    ),
                                    if ((entry['particulars']?.toString() ?? '')
                                        .trim()
                                        .isNotEmpty)
                                      Text(
                                        entry['particulars'].toString(),
                                        maxLines: 2,
                                        overflow: TextOverflow.ellipsis,
                                        style: Theme.of(context)
                                            .textTheme
                                            .bodySmall,
                                      ),
                                  ],
                                ),
                              ),
                              const SizedBox(width: 12),
                              Flexible(
                                child: FittedBox(
                                  fit: BoxFit.scaleDown,
                                  alignment: Alignment.centerRight,
                                  child: Text(
                                    entry['debit_label']?.toString() ??
                                        _inr.format(
                                          double.tryParse(
                                                '${entry['debit'] ?? 0}',
                                              ) ??
                                              0,
                                        ),
                                    maxLines: 1,
                                    style: Theme.of(context)
                                        .textTheme
                                        .titleSmall
                                        ?.copyWith(
                                          fontWeight: FontWeight.w800,
                                          color: AppColors.primary,
                                        ),
                                  ),
                                ),
                              ),
                            ],
                          ),
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
