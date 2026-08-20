import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/production_api.dart';
import '../widgets/mark_as_dispatched_dialog.dart';
import '../widgets/production_order_list_card.dart';

enum _ProductionOrderTabKey {
  approved,
  sentForBill,
  billed,
  dispatched,
  rejected,
}

/// Production Supervisor Orders: status tabs with live counts.
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

  Future<ProductionOrderListResult>? _approvedFuture;
  Future<ProductionOrderListResult>? _sentForBillFuture;
  Future<ProductionOrderListResult>? _billedFuture;
  Future<ProductionOrderListResult>? _dispatchedFuture;
  Future<ProductionOrderListResult>? _rejectedFuture;

  ProductionOrderCounts _counts = const ProductionOrderCounts(
    approved: 0,
    sentForBill: 0,
    billed: 0,
    dispatched: 0,
  );

  static const _tabs = _ProductionOrderTabKey.values;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: _tabs.length, vsync: this);
    _api = ProductionApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
          .dio,
    );
    _reloadAll();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  String _statusFor(_ProductionOrderTabKey tab) => switch (tab) {
        _ProductionOrderTabKey.approved => 'approved',
        _ProductionOrderTabKey.sentForBill => 'sent_for_bill',
        _ProductionOrderTabKey.billed => 'billed',
        _ProductionOrderTabKey.dispatched => 'dispatched',
        _ProductionOrderTabKey.rejected => 'rejected',
      };

  Future<ProductionOrderListResult> _loadTab(_ProductionOrderTabKey tab) {
    return _api.listOrders(status: _statusFor(tab));
  }

  void _reloadAll() {
    setState(() {
      _approvedFuture = _loadTab(_ProductionOrderTabKey.approved);
      _sentForBillFuture = _loadTab(_ProductionOrderTabKey.sentForBill);
      _billedFuture = _loadTab(_ProductionOrderTabKey.billed);
      _dispatchedFuture = _loadTab(_ProductionOrderTabKey.dispatched);
      _rejectedFuture = _loadTab(_ProductionOrderTabKey.rejected);
    });
  }

  Future<void> _refreshAll() async {
    _reloadAll();
    await Future.wait([
      _approvedFuture!,
      _sentForBillFuture!,
      _billedFuture!,
      _dispatchedFuture!,
      _rejectedFuture!,
    ]);
  }

  void _updateCounts(ProductionOrderCounts counts) {
    if (_counts.approved == counts.approved &&
        _counts.sentForBill == counts.sentForBill &&
        _counts.billed == counts.billed &&
        _counts.dispatched == counts.dispatched &&
        _counts.rejected == counts.rejected) {
      return;
    }
    setState(() => _counts = counts);
  }

  Future<void> _openOrder(int id) async {
    final result = await context.push<Object?>('/production/orders/$id');
    if (!mounted) return;
    await _refreshAll();
    if (!mounted) return;
    if (result == 'dispatched') {
      _tabController.animateTo(_ProductionOrderTabKey.dispatched.index);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Order marked as dispatched.')),
      );
    } else if (result == 'sent_for_bill') {
      _tabController.animateTo(_ProductionOrderTabKey.sentForBill.index);
    }
  }

  Future<void> _dispatchFromList(Map<String, dynamic> order) async {
    final ok = await confirmAndDispatchProductionOrder(
      context: context,
      api: _api,
      order: order,
    );
    if (!ok || !mounted) return;
    await _refreshAll();
    if (!mounted) return;
    _tabController.animateTo(_ProductionOrderTabKey.dispatched.index);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'Orders',
        auth: widget.auth,
        bottom: TabBar(
          controller: _tabController,
          isScrollable: true,
          tabAlignment: TabAlignment.start,
          tabs: [
            Tab(text: 'Approved (${_counts.approved})'),
            Tab(text: 'Sent for Bill (${_counts.sentForBill})'),
            Tab(text: 'Billed (${_counts.billed})'),
            Tab(text: 'Dispatched (${_counts.dispatched})'),
            Tab(text: 'Rejected (${_counts.rejected})'),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabController,
        children: [
          _ProductionOrderTab(
            future: _approvedFuture,
            statusKey: 'approved',
            emptyMessage: 'No approved orders.',
            canDispatch: widget.auth.permissions.canDispatchOrders,
            onCounts: _updateCounts,
            onRefresh: _refreshAll,
            onTap: _openOrder,
            onDispatch: _dispatchFromList,
          ),
          _ProductionOrderTab(
            future: _sentForBillFuture,
            statusKey: 'sent_for_bill',
            emptyMessage: 'No orders sent for billing.',
            canDispatch: widget.auth.permissions.canDispatchOrders,
            onCounts: _updateCounts,
            onRefresh: _refreshAll,
            onTap: _openOrder,
            onDispatch: _dispatchFromList,
          ),
          _ProductionOrderTab(
            future: _billedFuture,
            statusKey: 'billed',
            emptyMessage: 'No billed orders.',
            canDispatch: widget.auth.permissions.canDispatchOrders,
            onCounts: _updateCounts,
            onRefresh: _refreshAll,
            onTap: _openOrder,
            onDispatch: _dispatchFromList,
          ),
          _ProductionOrderTab(
            future: _dispatchedFuture,
            statusKey: 'dispatched',
            emptyMessage: 'No dispatched orders.',
            canDispatch: widget.auth.permissions.canDispatchOrders,
            onCounts: _updateCounts,
            onRefresh: _refreshAll,
            onTap: _openOrder,
            onDispatch: _dispatchFromList,
          ),
          _ProductionOrderTab(
            future: _rejectedFuture,
            statusKey: 'rejected',
            emptyMessage: 'No rejected orders.',
            canDispatch: widget.auth.permissions.canDispatchOrders,
            onCounts: _updateCounts,
            onRefresh: _refreshAll,
            onTap: _openOrder,
            onDispatch: _dispatchFromList,
          ),
        ],
      ),
    );
  }
}

class _ProductionOrderTab extends StatelessWidget {
  const _ProductionOrderTab({
    required this.future,
    required this.statusKey,
    required this.emptyMessage,
    required this.canDispatch,
    required this.onCounts,
    required this.onRefresh,
    required this.onTap,
    required this.onDispatch,
  });

  final Future<ProductionOrderListResult>? future;
  final String statusKey;
  final String emptyMessage;
  final bool canDispatch;
  final void Function(ProductionOrderCounts counts) onCounts;
  final Future<void> Function() onRefresh;
  final void Function(int id) onTap;
  final Future<void> Function(Map<String, dynamic> order) onDispatch;

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 0,
    );
    final dateTime = DateFormat('d MMM yyyy, h:mm a');

    return FutureBuilder<ProductionOrderListResult>(
      future: future,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting &&
            !snapshot.hasData) {
          return const PgLoadingState();
        }

        if (snapshot.hasError) {
          return PgErrorState(
            message: 'Unable to load orders. Tap to retry.\n'
                '${errorMessage(snapshot.error)}',
            onRetry: onRefresh,
          );
        }

        final result = snapshot.data!;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (result.counts != null) onCounts(result.counts!);
        });
        final orders = result.orders;

        return RefreshIndicator(
          onRefresh: onRefresh,
          child: orders.isEmpty
              ? ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  children: [PgEmptyState(message: emptyMessage)],
                )
              : ListView.builder(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(AppSpacing.screenPadding),
                  itemCount: orders.length,
                  itemBuilder: (context, index) {
                    final order = orders[index];
                    final id = int.tryParse('${order['id'] ?? 0}') ?? 0;
                    final canDispatchThis = canDispatch &&
                        statusKey == 'billed' &&
                        order['can_dispatch'] == true;

                    return ProductionOrderListCard(
                      order: order,
                      statusKey: statusKey,
                      currency: currency,
                      dateTime: dateTime,
                      onTap: () {
                        if (id > 0) onTap(id);
                      },
                      showDispatchAction: canDispatchThis,
                      onDispatch: canDispatchThis
                          ? () => onDispatch(order)
                          : null,
                    );
                  },
                ),
        );
      },
    );
  }
}
