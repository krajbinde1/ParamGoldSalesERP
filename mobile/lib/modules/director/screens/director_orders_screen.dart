import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../../manager/api/manager_api.dart';
import '../../manager/screens/manager_orders_screen.dart';
import '../api/director_api.dart';
import 'director_order_pipeline_section.dart';

enum _DirectorOrderTabKey {
  pending,
  approved,
  onHold,
  returned,
  sentForBill,
  billed,
  dispatched,
  rejected,
}

/// Director company-wide order monitoring — Manager-style list, view-only detail.
class DirectorOrdersScreen extends StatefulWidget {
  const DirectorOrdersScreen({
    super.key,
    required this.auth,
    this.initialStatus,
  });

  final AuthController auth;
  final String? initialStatus;

  @override
  State<DirectorOrdersScreen> createState() => _DirectorOrdersScreenState();
}

class _DirectorOrdersScreenState extends State<DirectorOrdersScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  late DirectorApi _api;

  Future<DirectorOrderListResult>? _pendingFuture;
  Future<DirectorOrderListResult>? _approvedFuture;
  Future<DirectorOrderListResult>? _onHoldFuture;
  Future<DirectorOrderListResult>? _returnedFuture;
  Future<DirectorOrderListResult>? _sentForBillFuture;
  Future<DirectorOrderListResult>? _billedFuture;
  Future<DirectorOrderListResult>? _dispatchedFuture;
  Future<DirectorOrderListResult>? _rejectedFuture;

  ManagerOrderCounts _counts = const ManagerOrderCounts(
    pendingApproval: 0,
    approved: 0,
    rejected: 0,
    dispatched: 0,
    billed: 0,
    all: 0,
  );

  static const _tabs = [
    _DirectorOrderTabKey.pending,
    _DirectorOrderTabKey.approved,
    _DirectorOrderTabKey.onHold,
    _DirectorOrderTabKey.returned,
    _DirectorOrderTabKey.sentForBill,
    _DirectorOrderTabKey.billed,
    _DirectorOrderTabKey.dispatched,
    _DirectorOrderTabKey.rejected,
  ];

  @override
  void initState() {
    super.initState();
    final initialIndex = switch (widget.initialStatus) {
      'approved' => 1,
      'on_hold' || 'hold' => 2,
      'reverted_to_manager' || 'returned' || 'returned_by_production' => 3,
      'pending_for_billing' || 'sent_for_bill' => 4,
      'billed' => 5,
      'dispatched' => 6,
      'rejected' => 7,
      'pending' || 'pending_approval' || 'placed' => 0,
      _ => 0,
    };
    _tabController = TabController(
      length: _tabs.length,
      vsync: this,
      initialIndex: initialIndex,
    );
    _api = DirectorApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    );
    _reloadAll();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<DirectorOrderListResult> _loadTab(_DirectorOrderTabKey tab) {
    return _api.listOrders(
      status: switch (tab) {
        _DirectorOrderTabKey.pending => 'pending_approval',
        _DirectorOrderTabKey.approved => 'approved',
        _DirectorOrderTabKey.onHold => 'on_hold',
        _DirectorOrderTabKey.returned => 'reverted_to_manager',
        _DirectorOrderTabKey.sentForBill => 'pending_for_billing',
        _DirectorOrderTabKey.billed => 'billed',
        _DirectorOrderTabKey.dispatched => 'dispatched',
        _DirectorOrderTabKey.rejected => 'rejected',
      },
    );
  }

  void _reloadAll() {
    setState(() {
      _pendingFuture = _loadTab(_DirectorOrderTabKey.pending);
      _approvedFuture = _loadTab(_DirectorOrderTabKey.approved);
      _onHoldFuture = _loadTab(_DirectorOrderTabKey.onHold);
      _returnedFuture = _loadTab(_DirectorOrderTabKey.returned);
      _sentForBillFuture = _loadTab(_DirectorOrderTabKey.sentForBill);
      _billedFuture = _loadTab(_DirectorOrderTabKey.billed);
      _dispatchedFuture = _loadTab(_DirectorOrderTabKey.dispatched);
      _rejectedFuture = _loadTab(_DirectorOrderTabKey.rejected);
    });
  }

  Future<void> _refreshAll() async {
    _reloadAll();
    await Future.wait([
      _pendingFuture!,
      _approvedFuture!,
      _onHoldFuture!,
      _returnedFuture!,
      _sentForBillFuture!,
      _billedFuture!,
      _dispatchedFuture!,
      _rejectedFuture!,
    ]);
  }

  void _updateCounts(ManagerOrderCounts counts) {
    if (_counts.pendingApproval == counts.pendingApproval &&
        _counts.approved == counts.approved &&
        _counts.onHold == counts.onHold &&
        _counts.returnedByProduction == counts.returnedByProduction &&
        _counts.sentForBill == counts.sentForBill &&
        _counts.billed == counts.billed &&
        _counts.dispatched == counts.dispatched &&
        _counts.rejected == counts.rejected) {
      return;
    }
    setState(() => _counts = counts);
  }

  Future<void> _openOrderDetail(int orderId) async {
    await context.push('/director/orders/$orderId');
    if (!mounted) return;
    await _refreshAll();
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
          title: 'Order Monitoring',
          auth: widget.auth,
          showBack: true,
          onBack: () => smartBack(context),
          bottom: TabBar(
            controller: _tabController,
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            tabs: [
              Tab(text: 'Pending (${_counts.pendingApproval})'),
              Tab(text: 'Approved (${_counts.approved})'),
              Tab(text: 'On Hold (${_counts.onHold})'),
              Tab(text: 'Returned (${_counts.returnedByProduction})'),
              Tab(text: 'For Bill (${_counts.sentForBill})'),
              Tab(text: 'Billed (${_counts.billed})'),
              Tab(text: 'Dispatched (${_counts.dispatched})'),
              Tab(text: 'Rejected (${_counts.rejected})'),
            ],
          ),
        ),
        body: TabBarView(
          controller: _tabController,
          children: [
            _DirectorOrderTab(
              future: _pendingFuture,
              emptyMessage: 'No pending orders.',
              onCounts: _updateCounts,
              onRefresh: _refreshAll,
              onTap: _openOrderDetail,
            ),
            _DirectorOrderTab(
              future: _approvedFuture,
              emptyMessage: 'No approved orders.',
              onCounts: _updateCounts,
              onRefresh: _refreshAll,
              onTap: _openOrderDetail,
            ),
            _DirectorOrderTab(
              future: _onHoldFuture,
              emptyMessage: 'No orders on hold.',
              onCounts: _updateCounts,
              onRefresh: _refreshAll,
              onTap: _openOrderDetail,
            ),
            _DirectorOrderTab(
              future: _returnedFuture,
              emptyMessage: 'No orders returned to manager.',
              onCounts: _updateCounts,
              onRefresh: _refreshAll,
              onTap: _openOrderDetail,
            ),
            _DirectorOrderTab(
              future: _sentForBillFuture,
              emptyMessage: 'No orders pending billing.',
              onCounts: _updateCounts,
              onRefresh: _refreshAll,
              onTap: _openOrderDetail,
            ),
            _DirectorOrderTab(
              future: _billedFuture,
              emptyMessage: 'No billed orders.',
              onCounts: _updateCounts,
              onRefresh: _refreshAll,
              onTap: _openOrderDetail,
            ),
            _DirectorOrderTab(
              future: _dispatchedFuture,
              emptyMessage: 'No dispatched orders.',
              onCounts: _updateCounts,
              onRefresh: _refreshAll,
              onTap: _openOrderDetail,
            ),
            _DirectorOrderTab(
              future: _rejectedFuture,
              emptyMessage: 'No rejected orders.',
              onCounts: _updateCounts,
              onRefresh: _refreshAll,
              onTap: _openOrderDetail,
            ),
          ],
        ),
      ),
    );
  }
}

class _DirectorOrderTab extends StatelessWidget {
  const _DirectorOrderTab({
    required this.future,
    required this.emptyMessage,
    required this.onCounts,
    required this.onRefresh,
    required this.onTap,
  });

  final Future<DirectorOrderListResult>? future;
  final String emptyMessage;
  final void Function(ManagerOrderCounts counts) onCounts;
  final Future<void> Function() onRefresh;
  final void Function(int id) onTap;

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(locale: 'en_IN', symbol: '₹');
    final dateFormat = DateFormat('dd MMM yyyy, hh:mm a');

    return FutureBuilder<DirectorOrderListResult>(
      future: future,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting &&
            !snapshot.hasData) {
          return const PgLoadingState();
        }

        if (snapshot.hasError) {
          return RefreshIndicator(
            onRefresh: onRefresh,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                PgErrorState(message: errorMessage(snapshot.error)),
              ],
            ),
          );
        }

        final result = snapshot.data!;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          onCounts(result.counts);
        });
        final orders = result.orders;

        if (orders.isEmpty) {
          return RefreshIndicator(
            onRefresh: onRefresh,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: [
                SizedBox(
                  height: MediaQuery.sizeOf(context).height * 0.5,
                  child: PgEmptyState(message: emptyMessage),
                ),
              ],
            ),
          );
        }

        return RefreshIndicator(
          onRefresh: onRefresh,
          child: ListView.builder(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            itemCount: orders.length,
            itemBuilder: (context, index) {
              final order = orders[index];
              final statusLabel = order['status_label']?.toString();
              final status = order['status']?.toString() ?? '';
              final createdAt =
                  DateTime.tryParse(order['created_at']?.toString() ?? '');
              final dealerLocation = order['dealer_location']?.toString() ?? '';
              final village = order['dealer_village']?.toString().trim() ?? '';
              final taluka = order['dealer_taluka']?.toString().trim() ?? '';
              final district =
                  order['dealer_district']?.toString().trim() ?? '';
              final locationExtra = [
                if (taluka.isNotEmpty) taluka,
                if (district.isNotEmpty) district,
              ].join(', ');

              return PgCard(
                onTap: () => onTap(int.tryParse('${order['id'] ?? 0}') ?? 0),
                margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            order['order_no']?.toString() ?? '-',
                            style:
                                Theme.of(context).textTheme.titleSmall?.copyWith(
                                      fontWeight: FontWeight.w700,
                                    ),
                          ),
                        ),
                        Text(
                          currency.format(
                            double.tryParse('${order['grand_total'] ?? 0}') ?? 0,
                          ),
                          style: Theme.of(context).textTheme.titleSmall,
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(
                      createdAt == null
                          ? (order['order_date']?.toString() ?? '-')
                          : dateFormat.format(createdAt.toLocal()),
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      order['dealer_name']?.toString() ?? '-',
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                    ),
                    if (village.isNotEmpty)
                      Text(
                        village,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    if (locationExtra.isNotEmpty)
                      Text(
                        locationExtra,
                        style: Theme.of(context).textTheme.bodySmall,
                      )
                    else if (village.isEmpty && dealerLocation.isNotEmpty)
                      Text(
                        dealerLocation,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    Text(
                      'Sales Person: ${order['employee_name'] ?? '-'}',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                    if (statusLabel != null) ...[
                      const SizedBox(height: 8),
                      PgStatusBadge(
                        label: statusLabel,
                        tone: PgStatusRules.orderTone(status),
                      ),
                    ],
                  ],
                ),
              );
            },
          ),
        );
      },
    );
  }
}

class DirectorOrderDetailScreen extends StatelessWidget {
  const DirectorOrderDetailScreen({
    super.key,
    required this.auth,
    required this.orderId,
  });

  final AuthController auth;
  final int orderId;

  @override
  Widget build(BuildContext context) {
    final api = DirectorApi(
      ApiClient(SessionStore(), onUnauthorized: auth.sessionExpired).dio,
    );

    return ManagerOrderDetailScreen(
      auth: auth,
      orderId: orderId,
      viewOnly: true,
      title: 'Order Details',
      loadOrder: api.getOrder,
    );
  }
}

/// Single-list Director order view used by Today Sales and Pending Orders cards.
class DirectorFilteredOrdersScreen extends StatefulWidget {
  const DirectorFilteredOrdersScreen({
    super.key,
    required this.auth,
    required this.title,
    required this.emptyMessage,
    this.status,
    this.todayOnly = false,
    this.showOrderPipeline = false,
  });

  final AuthController auth;
  final String title;
  final String emptyMessage;
  final String? status;
  final bool todayOnly;
  final bool showOrderPipeline;

  @override
  State<DirectorFilteredOrdersScreen> createState() =>
      _DirectorFilteredOrdersScreenState();
}

class _DirectorFilteredOrdersScreenState
    extends State<DirectorFilteredOrdersScreen> {
  late Future<DirectorOrderListResult> _future;
  Future<DirectorDashboardData>? _dashboardFuture;
  late final DirectorApi _api;

  @override
  void initState() {
    super.initState();
    _api = DirectorApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    );
    _future = _load();
    if (widget.showOrderPipeline) {
      _dashboardFuture = _api.loadDashboard(period: 'month');
    }
  }

  Future<DirectorOrderListResult> _load() async {
    final today = DateFormat('yyyy-MM-dd').format(DateTime.now());
    const perPage = 100;
    var page = 1;
    final orders = <Map<String, dynamic>>[];
    ManagerOrderCounts counts = const ManagerOrderCounts(
      pendingApproval: 0,
      approved: 0,
      rejected: 0,
      dispatched: 0,
      billed: 0,
      all: 0,
    );
    var lastPage = 1;
    var total = 0;

    do {
      final result = await _api.listOrders(
        status: widget.status,
        dateFrom: widget.todayOnly ? today : null,
        dateTo: widget.todayOnly ? today : null,
        page: page,
        perPage: perPage,
      );
      orders.addAll(result.orders);
      counts = result.counts;
      lastPage = result.lastPage;
      total = result.total;
      page++;
    } while (page <= lastPage && page <= 20);

    return DirectorOrderListResult(
      orders: orders,
      total: total,
      lastPage: lastPage,
      counts: counts,
    );
  }

  Future<void> _reload() async {
    setState(() {
      _future = _load();
      if (widget.showOrderPipeline) {
        _dashboardFuture = _api.loadDashboard(period: 'month');
      }
    });
    await _future;
  }

  Future<void> _openOrder(int orderId) async {
    if (orderId <= 0) return;
    await context.push('/director/orders/$orderId');
    if (!mounted) return;
    await _reload();
  }

  Future<void> _openPipeline(String path) async {
    await context.push(path);
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
          title: widget.title,
          auth: widget.auth,
          showBack: true,
          onBack: () => smartBack(context),
        ),
        body: widget.showOrderPipeline
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  FutureBuilder<DirectorDashboardData>(
                    future: _dashboardFuture,
                    builder: (context, snapshot) {
                      if (!snapshot.hasData) {
                        return const SizedBox.shrink();
                      }
                      return Padding(
                        padding: const EdgeInsets.fromLTRB(
                          AppSpacing.screenPadding,
                          AppSpacing.screenPadding,
                          AppSpacing.screenPadding,
                          0,
                        ),
                        child: DirectorOrderPipelineSection(
                          data: snapshot.data!,
                          onOpen: _openPipeline,
                        ),
                      );
                    },
                  ),
                  Expanded(
                    child: _DirectorOrderTab(
                      future: _future,
                      emptyMessage: widget.emptyMessage,
                      onCounts: (_) {},
                      onRefresh: _reload,
                      onTap: _openOrder,
                    ),
                  ),
                ],
              )
            : _DirectorOrderTab(
                future: _future,
                emptyMessage: widget.emptyMessage,
                onCounts: (_) {},
                onRefresh: _reload,
                onTap: _openOrder,
              ),
      ),
    );
  }
}
