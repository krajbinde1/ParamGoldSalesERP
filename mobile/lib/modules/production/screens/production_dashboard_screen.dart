import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/utils/order_number.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_metric_card.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../../orders/models/order.dart';
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
  late Future<_ProductionOrdersBundle> _bundleFuture;
  int _dispatchedCount = 0;

  @override
  void initState() {
    super.initState();
    // TEMP DEBUG — actual runtime Orders screen.
    // ignore: avoid_print
    print(
      '[PS ApprovedOrders DEBUG] ProductionOrdersScreen.initState '
      'file=production_dashboard_screen.dart '
      'service=ProductionApi.listOrders',
    );
    debugPrint(
      '[PS ApprovedOrders DEBUG] ProductionOrdersScreen.initState '
      'file=production_dashboard_screen.dart',
    );
    _tabController = TabController(length: 4, vsync: this);
    _tabController.addListener(() {
      if (_tabController.indexIsChanging) return;
      // ignore: avoid_print
      print(
        '[PS ApprovedOrders DEBUG] Orders TabBar index=${_tabController.index} '
        '(0=Approved Orders, 1=Sent for Bill, 2=Billed, 3=Dispatched)',
      );
    });
    _api = ProductionApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
          .dio,
    );
    _bundleFuture = _loadBundle();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<_ProductionOrdersBundle> _loadBundle() async {
    // TEMP DEBUG
    // ignore: avoid_print
    print(
      '[PS ApprovedOrders DEBUG] ProductionOrdersScreen._loadBundle START',
    );
    // Load Approved first / independently so other tab failures cannot blank it.
    final approvedResult = await _loadApprovedOrders();

    ProductionOrderListResult sent;
    ProductionOrderListResult billed;
    ProductionOrderListResult dispatched;
    try {
      sent = await _api.listOrders(status: 'sent_for_bill');
    } catch (_) {
      sent = const ProductionOrderListResult(orders: []);
    }
    try {
      billed = await _api.listOrders(status: 'billed');
    } catch (_) {
      billed = const ProductionOrderListResult(orders: []);
    }
    try {
      // Exact backend status: Order::STATUS_DISPATCHED = 'dispatched'
      dispatched = await _api.listOrders(status: 'dispatched');
    } catch (_) {
      dispatched = const ProductionOrderListResult(orders: []);
    }

    final counts = approvedResult.counts ??
        sent.counts ??
        billed.counts ??
        dispatched.counts;

    final approvedCount = counts?.approved ?? approvedResult.orders.length;
    final sentForBillCount = counts?.sentForBill ?? sent.orders.length;
    final billedCount = counts?.billed ?? billed.orders.length;
    final dispatchedCount = counts?.dispatched ?? dispatched.orders.length;

    // ignore: avoid_print
    print(
      '[PS ApprovedOrders DEBUG] ProductionOrdersScreen._loadBundle DONE '
      'approvedCount=${approvedResult.orders.length} '
      'sent=${sent.orders.length} billed=${billed.orders.length} '
      'dispatched=${dispatched.orders.length}',
    );

    if (kDebugMode) {
      debugPrint(
        'Production orders loaded: '
        'approved=${approvedResult.orders.length}, '
        'sent=${sent.orders.length}, '
        'billed=${billed.orders.length}, '
        'dispatched=${dispatched.orders.length}, '
        'counts=${counts?.approved}/${counts?.sentForBill}/'
        '${counts?.billed}/${counts?.dispatched}',
      );
    }

    if (mounted) {
      setState(() {
        _dispatchedCount = dispatchedCount;
      });
    }

    return _ProductionOrdersBundle(
      approved: approvedResult.orders,
      sentForBill: sent.orders,
      billed: billed.orders,
      dispatched: dispatched.orders,
      approvedCount: approvedCount,
      sentForBillCount: sentForBillCount,
      billedCount: billedCount,
      dispatchedCount: dispatchedCount,
    );
  }

  Future<ProductionOrderListResult> _loadApprovedOrders() async {
    // ignore: avoid_print
    print(
      '[PS ApprovedOrders DEBUG] _loadApprovedOrders → '
      'ProductionApi.listOrders(status: approved)',
    );
    try {
      final primary = await _api.listOrders(status: 'approved');
      // ignore: avoid_print
      print(
        '[PS ApprovedOrders DEBUG] _loadApprovedOrders primary count='
        '${primary.orders.length}',
      );
      if (primary.orders.isNotEmpty) {
        return primary;
      }

      // Fallback: unfiltered production list, then keep Manager-approved statuses.
      final all = await _api.listOrders();
      late final List<Map<String, dynamic>> approvedOnly;
      try {
        approvedOnly = all.orders
            .where(
              (order) => ProductionApi.isApprovedTabStatus(
                order['status']?.toString(),
              ),
            )
            .toList(growable: false);
      } catch (error, stackTrace) {
        // TEMP DEBUG: Approved Orders client filter only.
        // ignore: avoid_print
        print(
          '[PS ApprovedOrders DEBUG] Parsing/filter exception: $error',
        );
        // ignore: avoid_print
        print(
          '[PS ApprovedOrders DEBUG] Stack: $stackTrace',
        );
        rethrow;
      }

      // ignore: avoid_print
      print(
        '[PS ApprovedOrders DEBUG] Primary approved list empty; '
        'fallback filtered count=${approvedOnly.length}',
      );
      for (final order in approvedOnly) {
        // ignore: avoid_print
        print(
          '[PS ApprovedOrders DEBUG] fallback order '
          'id=${order['id']} '
          'order_number=${order['order_no'] ?? order['order_number']} '
          'status=${order['status']}',
        );
      }

      return ProductionOrderListResult(
        orders: approvedOnly,
        counts: all.counts ?? primary.counts,
      );
    } catch (error, stackTrace) {
      // ignore: avoid_print
      print(
        '[PS ApprovedOrders DEBUG] Parsing/filter exception: $error',
      );
      // ignore: avoid_print
      print(
        '[PS ApprovedOrders DEBUG] Stack: $stackTrace',
      );
      rethrow;
    }
  }

  Future<void> _refresh() async {
    final next = _loadBundle();
    setState(() => _bundleFuture = next);
    await next;
  }

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 0,
    );
    final dateTime = DateFormat('d MMM yyyy, h:mm a');

    return Scaffold(
      appBar: RoleAppBar(
        title: 'Orders',
        auth: widget.auth,
        bottom: TabBar(
          controller: _tabController,
          isScrollable: false,
          labelPadding: const EdgeInsets.symmetric(horizontal: 2),
          labelStyle: const TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w600,
            height: 1.1,
          ),
          unselectedLabelStyle: const TextStyle(
            fontSize: 11,
            height: 1.1,
          ),
          tabs: [
            const Tab(
              child: Text(
                'Approved Orders',
                textAlign: TextAlign.center,
                maxLines: 2,
              ),
            ),
            const Tab(
              child: Text(
                'Sent for Bill',
                textAlign: TextAlign.center,
                maxLines: 2,
              ),
            ),
            const Tab(
              child: Text(
                'Billed',
                textAlign: TextAlign.center,
                maxLines: 2,
              ),
            ),
            Tab(
              child: Text(
                'Dispatched  $_dispatchedCount',
                textAlign: TextAlign.center,
                maxLines: 2,
              ),
            ),
          ],
        ),
      ),
      body: FutureBuilder<_ProductionOrdersBundle>(
        future: _bundleFuture,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting &&
              !snapshot.hasData) {
            return const PgLoadingState();
          }

          if (snapshot.hasError) {
            return PgErrorState(
              message: 'Unable to load orders. Tap to retry.\n'
                  '${errorMessage(snapshot.error)}',
              onRetry: _refresh,
            );
          }

          final bundle = snapshot.data!;
          return Column(
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(
                  AppSpacing.screenPadding,
                  AppSpacing.md,
                  AppSpacing.screenPadding,
                  AppSpacing.sm,
                ),
                child: Column(
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: PgMetricCard(
                            title: 'Approved',
                            value: '${bundle.approvedCount}',
                            icon: const Icon(Icons.verified_outlined),
                            gradient: AppColors.greenGradient,
                          ),
                        ),
                        const SizedBox(width: AppSpacing.sm),
                        Expanded(
                          child: PgMetricCard(
                            title: 'Sent for Bill',
                            value: '${bundle.sentForBillCount}',
                            icon: const Icon(Icons.send_outlined),
                            gradient: AppColors.amberGradient,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    Row(
                      children: [
                        Expanded(
                          child: PgMetricCard(
                            title: 'Billed',
                            value: '${bundle.billedCount}',
                            icon: const Icon(Icons.receipt_long_outlined),
                            gradient: AppColors.blueGradient,
                          ),
                        ),
                        const SizedBox(width: AppSpacing.sm),
                        Expanded(
                          child: PgMetricCard(
                            title: 'Dispatched',
                            value: '${bundle.dispatchedCount}',
                            icon: const Icon(Icons.local_shipping_outlined),
                            gradient: AppColors.greenGradient,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              Expanded(
                child: TabBarView(
                  controller: _tabController,
                  children: [
                    _OrderList(
                      orders: bundle.approved,
                      currency: currency,
                      dateTime: dateTime,
                      emptyMessage: 'No approved orders.',
                      mode: _OrderListMode.approved,
                      onRefresh: _refresh,
                      onTap: (id) async {
                        await context.push('/production/orders/$id');
                        if (mounted) await _refresh();
                      },
                    ),
                    _OrderList(
                      orders: bundle.sentForBill,
                      currency: currency,
                      dateTime: dateTime,
                      emptyMessage: 'No orders sent for billing.',
                      mode: _OrderListMode.sentForBill,
                      onRefresh: _refresh,
                      onTap: (id) async {
                        await context.push('/production/orders/$id');
                        if (mounted) await _refresh();
                      },
                    ),
                    _OrderList(
                      orders: bundle.billed,
                      currency: currency,
                      dateTime: dateTime,
                      emptyMessage: 'No billed orders.',
                      mode: _OrderListMode.billed,
                      onRefresh: _refresh,
                      onTap: (id) async {
                        await context.push('/production/orders/$id');
                        if (mounted) await _refresh();
                      },
                    ),
                    _OrderList(
                      orders: bundle.dispatched,
                      currency: currency,
                      dateTime: dateTime,
                      emptyMessage: 'No dispatched orders.',
                      mode: _OrderListMode.dispatched,
                      onRefresh: _refresh,
                      onTap: (id) async {
                        await context.push('/production/orders/$id');
                        if (mounted) await _refresh();
                      },
                    ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

enum _OrderListMode { approved, sentForBill, billed, dispatched }

class _ProductionOrdersBundle {
  const _ProductionOrdersBundle({
    required this.approved,
    required this.sentForBill,
    required this.billed,
    required this.dispatched,
    required this.approvedCount,
    required this.sentForBillCount,
    required this.billedCount,
    required this.dispatchedCount,
  });

  final List<Map<String, dynamic>> approved;
  final List<Map<String, dynamic>> sentForBill;
  final List<Map<String, dynamic>> billed;
  final List<Map<String, dynamic>> dispatched;
  final int approvedCount;
  final int sentForBillCount;
  final int billedCount;
  final int dispatchedCount;
}

class _OrderList extends StatelessWidget {
  const _OrderList({
    required this.orders,
    required this.currency,
    required this.dateTime,
    required this.emptyMessage,
    required this.mode,
    required this.onTap,
    required this.onRefresh,
  });

  final List<Map<String, dynamic>> orders;
  final NumberFormat currency;
  final DateFormat dateTime;
  final String emptyMessage;
  final _OrderListMode mode;
  final void Function(int id) onTap;
  final Future<void> Function() onRefresh;

  String _formatDateTime(Object? raw) {
    if (raw == null) return '-';
    final parsed = DateTime.tryParse(raw.toString());
    if (parsed == null) return raw.toString();
    return dateTime.format(parsed.toLocal());
  }

  @override
  Widget build(BuildContext context) {
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
                final amount =
                    double.tryParse('${order['grand_total'] ?? 0}') ?? 0;
                final status = order['status']?.toString() ?? '';
                final freight = double.tryParse(
                      '${order['transport_amount'] ?? order['transport_freight'] ?? ''}',
                    );

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
                              productionOrderListNo(order),
                              style: Theme.of(context).textTheme.titleSmall,
                            ),
                          ),
                          PgStatusBadge(
                            label: OrderStatusRules.badgeLabel(
                              status,
                              statusLabel: order['status_label']?.toString(),
                            ),
                            tone: PgStatusRules.orderTone(status),
                          ),
                        ],
                      ),
                      const SizedBox(height: 6),
                      if (mode == _OrderListMode.dispatched) ...[
                        Text(
                          'Dealer: ${order['dealer_name'] ?? '-'}',
                          style: Theme.of(context).textTheme.bodyMedium,
                        ),
                        Text(
                          'Village: ${order['dealer_village'] ?? '-'}',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        Text(
                          'Sales Person: ${order['employee_name'] ?? '-'}',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        Text(
                          'Dispatch Date: ${_formatDateTime(order['dispatched_at'])}',
                          style:
                              Theme.of(context).textTheme.bodySmall?.copyWith(
                                    color: AppColors.textSecondary,
                                  ),
                        ),
                      ] else ...[
                        Text(
                          'Dealer: ${order['dealer_name'] ?? '-'}',
                          style: Theme.of(context).textTheme.bodyMedium,
                        ),
                        Text(
                          'Sales: ${order['employee_name'] ?? '-'}',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        Text(
                          'Date: ${_formatDateTime(order['order_date'] ?? order['created_at'])} • ${currency.format(amount)}',
                          style:
                              Theme.of(context).textTheme.bodySmall?.copyWith(
                                    color: AppColors.textSecondary,
                                  ),
                        ),
                        if ((order['payment_type']?.toString() ?? '').isNotEmpty)
                          Text(
                            'Payment: ${order['payment_type']}',
                            style: Theme.of(context)
                                .textTheme
                                .bodySmall
                                ?.copyWith(color: AppColors.textSecondary),
                          ),
                        if (mode != _OrderListMode.approved) ...[
                          if ((order['vehicle_number']?.toString() ?? '')
                              .isNotEmpty)
                            Text(
                              'Vehicle: ${order['vehicle_number']}',
                              style: Theme.of(context)
                                  .textTheme
                                  .bodySmall
                                  ?.copyWith(color: AppColors.textSecondary),
                            ),
                          if (freight != null)
                            Text(
                              'Freight: ${currency.format(freight)}',
                              style: Theme.of(context)
                                  .textTheme
                                  .bodySmall
                                  ?.copyWith(color: AppColors.textSecondary),
                            ),
                        ],
                        if (mode == _OrderListMode.sentForBill)
                          Text(
                            'Sent: ${_formatDateTime(order['sent_for_bill_at'])}',
                            style: Theme.of(context)
                                .textTheme
                                .bodySmall
                                ?.copyWith(color: AppColors.textSecondary),
                          ),
                        if (mode == _OrderListMode.billed) ...[
                          if ((order['bill_number']?.toString() ?? '')
                              .isNotEmpty)
                            Text(
                              'Bill No: ${order['bill_number']}',
                              style: Theme.of(context)
                                  .textTheme
                                  .bodySmall
                                  ?.copyWith(color: AppColors.textSecondary),
                            ),
                          Text(
                            'Bill Date: ${order['bill_date'] ?? '-'}',
                            style: Theme.of(context)
                                .textTheme
                                .bodySmall
                                ?.copyWith(color: AppColors.textSecondary),
                          ),
                          Text(
                            'Billed: ${_formatDateTime(order['billed_at'])}',
                            style: Theme.of(context)
                                .textTheme
                                .bodySmall
                                ?.copyWith(color: AppColors.textSecondary),
                          ),
                        ],
                      ],
                    ],
                  ),
                );
              },
            ),
    );
  }
}
