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
import '../../../core/widgets/design/pg_metric_card.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/production_api.dart';

class ProductionOrdersScreen extends StatefulWidget {
  const ProductionOrdersScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<ProductionOrdersScreen> createState() => _ProductionOrdersScreenState();
}

class _ProductionOrdersScreenState extends State<ProductionOrdersScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  late ProductionApi _api;
  late Future<List<Map<String, dynamic>>> _approvedFuture;
  late Future<List<Map<String, dynamic>>> _billedFuture;
  late Future<List<Map<String, dynamic>>> _dispatchedFuture;
  int _approvedCount = 0;
  int _billedCount = 0;
  int _dispatchedCount = 0;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
    _api = ProductionApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
          .dio,
    );
    _reload();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  void _reload() {
    setState(() {
      _approvedFuture = _loadStatus('approved');
      _billedFuture = _loadStatus('billed');
      _dispatchedFuture = _loadStatus('dispatched');
    });
    _loadDashboardCounts();
  }

  Future<List<Map<String, dynamic>>> _loadStatus(String status) async {
    final result = await _api.listOrders(status: status);
    final counts = result.counts;
    if (mounted && counts != null) {
      setState(() {
        _approvedCount = counts.approved;
        _billedCount = counts.billed;
        _dispatchedCount = counts.dispatched;
      });
    }
    return result.orders;
  }

  Future<void> _loadDashboardCounts() async {
    try {
      final dashboard = await _api.loadDashboard();
      if (!mounted) return;
      setState(() {
        _approvedCount = dashboard.approvedOrders;
        _billedCount = dashboard.billedOrders;
        _dispatchedCount = dashboard.dispatchedOrders;
      });
    } catch (_) {
      // Counts remain from list meta when available.
    }
  }

  Future<void> _refresh() async {
    _reload();
    await Future.wait([
      _approvedFuture,
      _billedFuture,
      _dispatchedFuture,
    ]);
  }

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 0,
    );

    return Scaffold(
      appBar: RoleAppBar(
        title: 'Orders',
        auth: widget.auth,
        bottom: TabBar(
          controller: _tabController,
          tabs: const [
            Tab(text: 'Approved'),
            Tab(text: 'Billed'),
            Tab(text: 'Dispatched'),
          ],
        ),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.screenPadding,
              AppSpacing.md,
              AppSpacing.screenPadding,
              AppSpacing.sm,
            ),
            child: Row(
              children: [
                Expanded(
                  child: PgMetricCard(
                    title: 'Approved',
                    value: '$_approvedCount',
                    icon: const Icon(Icons.verified_outlined),
                    gradient: AppColors.greenGradient,
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: PgMetricCard(
                    title: 'Billed',
                    value: '$_billedCount',
                    icon: const Icon(Icons.receipt_long_outlined),
                    gradient: AppColors.amberGradient,
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: PgMetricCard(
                    title: 'Dispatched',
                    value: '$_dispatchedCount',
                    icon: const Icon(Icons.local_shipping_outlined),
                    gradient: AppColors.blueGradient,
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            child: TabBarView(
              controller: _tabController,
              children: [
                _OrderList(
                  future: _approvedFuture,
                  currency: currency,
                  emptyMessage: 'No approved orders.',
                  onRefresh: _refresh,
                  onTap: (id) async {
                    await context.push('/production/orders/$id');
                    if (mounted) _refresh();
                  },
                ),
                _OrderList(
                  future: _billedFuture,
                  currency: currency,
                  emptyMessage: 'No billed orders ready for dispatch.',
                  onRefresh: _refresh,
                  onTap: (id) async {
                    await context.push('/production/orders/$id');
                    if (mounted) _refresh();
                  },
                ),
                _OrderList(
                  future: _dispatchedFuture,
                  currency: currency,
                  emptyMessage: 'No dispatched orders.',
                  onRefresh: _refresh,
                  onTap: (id) async {
                    await context.push('/production/orders/$id');
                    if (mounted) _refresh();
                  },
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _OrderList extends StatelessWidget {
  const _OrderList({
    required this.future,
    required this.currency,
    required this.emptyMessage,
    required this.onTap,
    required this.onRefresh,
  });

  final Future<List<Map<String, dynamic>>> future;
  final NumberFormat currency;
  final String emptyMessage;
  final void Function(int id) onTap;
  final Future<void> Function() onRefresh;

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: onRefresh,
      child: FutureBuilder<List<Map<String, dynamic>>>(
        future: future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(message: errorMessage(snapshot.error));
          }
          final orders = snapshot.data ?? const [];
          if (orders.isEmpty) {
            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: [PgEmptyState(message: emptyMessage)],
            );
          }
          return ListView.builder(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            itemCount: orders.length,
            itemBuilder: (context, index) {
              final order = orders[index];
              final id = int.tryParse('${order['id'] ?? 0}') ?? 0;
              final amount =
                  double.tryParse('${order['grand_total'] ?? 0}') ?? 0;
              return PgCard(
                margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                onTap: () => onTap(id),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            order['order_no']?.toString() ?? '-',
                            style: Theme.of(context).textTheme.titleSmall,
                          ),
                        ),
                        PgStatusBadge(
                          label:
                              order['status_label']?.toString() ??
                              order['status']?.toString() ??
                              '-',
                          tone: PgStatusRules.orderTone(
                            order['status']?.toString() ?? '',
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(
                      'Dealer: ${order['dealer_name'] ?? '-'}',
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                    Text(
                      'Employee: ${order['employee_name'] ?? '-'}',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                    Text(
                      'Date: ${order['order_date'] ?? '-'} • ${currency.format(amount)}',
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
    );
  }
}
