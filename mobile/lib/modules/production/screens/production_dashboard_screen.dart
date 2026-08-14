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

/// Production Supervisor Orders dashboard: status cards + recent orders.
class ProductionOrdersScreen extends StatefulWidget {
  const ProductionOrdersScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<ProductionOrdersScreen> createState() => _ProductionOrdersScreenState();
}

class _ProductionOrdersScreenState extends State<ProductionOrdersScreen> {
  late ProductionApi _api;
  late Future<_OrdersDashboardData> _future;

  @override
  void initState() {
    super.initState();
    _api = ProductionApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
          .dio,
    );
    _future = _load();
  }

  Future<_OrdersDashboardData> _load() async {
    final result = await _api.listOrders();
    final counts = result.counts;
    final recent = _pickRecent(result.orders, limit: 5);

    return _OrdersDashboardData(
      approvedCount: counts?.approved ?? 0,
      sentForBillCount: counts?.sentForBill ?? 0,
      billedCount: counts?.billed ?? 0,
      dispatchedCount: counts?.dispatched ?? 0,
      recent: recent,
    );
  }

  List<Map<String, dynamic>> _pickRecent(
    List<Map<String, dynamic>> orders, {
    required int limit,
  }) {
    final sorted = List<Map<String, dynamic>>.from(orders)
      ..sort((a, b) => _orderSortKey(b).compareTo(_orderSortKey(a)));
    if (sorted.length <= limit) return sorted;
    return sorted.sublist(0, limit);
  }

  DateTime _orderSortKey(Map<String, dynamic> order) {
    for (final key in [
      'dispatched_at',
      'billed_at',
      'sent_for_bill_at',
      'approved_at',
      'created_at',
      'order_date',
    ]) {
      final parsed = DateTime.tryParse('${order[key] ?? ''}');
      if (parsed != null) return parsed.toLocal();
    }
    return DateTime.fromMillisecondsSinceEpoch(0);
  }

  Future<void> _refresh() async {
    final next = _load();
    setState(() => _future = next);
    await next;
  }

  Future<void> _openStatusList(String status) async {
    await context.push('/production/orders/by-status/$status');
    if (mounted) await _refresh();
  }

  Future<void> _openOrder(int id) async {
    await context.push('/production/orders/$id');
    if (mounted) await _refresh();
  }

  @override
  Widget build(BuildContext context) {
    final recentDate = DateFormat('d MMM • h:mm a');

    return Scaffold(
      appBar: RoleAppBar(title: 'Orders', auth: widget.auth),
      body: FutureBuilder<_OrdersDashboardData>(
        future: _future,
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

          final data = snapshot.data!;
          return RefreshIndicator(
            onRefresh: _refresh,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.screenPadding,
                AppSpacing.md,
                AppSpacing.screenPadding,
                AppSpacing.xl,
              ),
              children: [
                Row(
                  children: [
                    Expanded(
                      child: SizedBox(
                        height: 128,
                        child: PgMetricCard(
                          title: 'Approved',
                          value: '${data.approvedCount}',
                          icon: const Icon(Icons.verified_outlined),
                          gradient: AppColors.greenGradient,
                          onTap: () => _openStatusList('approved'),
                        ),
                      ),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: SizedBox(
                        height: 128,
                        child: PgMetricCard(
                          title: 'Sent for Bill',
                          value: '${data.sentForBillCount}',
                          icon: const Icon(Icons.send_outlined),
                          gradient: AppColors.amberGradient,
                          onTap: () => _openStatusList('sent_for_bill'),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.sm),
                Row(
                  children: [
                    Expanded(
                      child: SizedBox(
                        height: 128,
                        child: PgMetricCard(
                          title: 'Billed',
                          value: '${data.billedCount}',
                          icon: const Icon(Icons.receipt_long_outlined),
                          gradient: AppColors.blueGradient,
                          onTap: () => _openStatusList('billed'),
                        ),
                      ),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: SizedBox(
                        height: 128,
                        child: PgMetricCard(
                          title: 'Dispatched',
                          value: '${data.dispatchedCount}',
                          icon: const Icon(Icons.local_shipping_outlined),
                          gradient: AppColors.greenGradient,
                          onTap: () => _openStatusList('dispatched'),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.lg),
                Text(
                  'Recent Orders',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                ),
                const SizedBox(height: AppSpacing.sm),
                if (data.recent.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: AppSpacing.lg),
                    child: PgEmptyState(message: 'No recent orders'),
                  )
                else
                  ...data.recent.map(
                    (order) => _RecentOrderTile(
                      order: order,
                      dateFormat: recentDate,
                      onTap: () {
                        final id = int.tryParse('${order['id'] ?? 0}') ?? 0;
                        if (id > 0) _openOrder(id);
                      },
                    ),
                  ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _OrdersDashboardData {
  const _OrdersDashboardData({
    required this.approvedCount,
    required this.sentForBillCount,
    required this.billedCount,
    required this.dispatchedCount,
    required this.recent,
  });

  final int approvedCount;
  final int sentForBillCount;
  final int billedCount;
  final int dispatchedCount;
  final List<Map<String, dynamic>> recent;
}

class _RecentOrderTile extends StatelessWidget {
  const _RecentOrderTile({
    required this.order,
    required this.dateFormat,
    required this.onTap,
  });

  final Map<String, dynamic> order;
  final DateFormat dateFormat;
  final VoidCallback onTap;

  String _displayDate() {
    final status = order['status']?.toString() ?? '';
    Object? raw;
    switch (status) {
      case 'dispatched':
        raw = order['dispatched_at'];
      case 'billed':
        raw = order['billed_at'] ?? order['bill_date'];
      case 'pending_for_billing':
        raw = order['sent_for_bill_at'];
      case 'approved':
        raw = order['approved_at'];
      default:
        raw = null;
    }
    raw ??= order['created_at'] ?? order['order_date'];
    final parsed = DateTime.tryParse('$raw');
    if (parsed == null) return '-';
    return dateFormat.format(parsed.toLocal());
  }

  @override
  Widget build(BuildContext context) {
    final status = order['status']?.toString() ?? '';

    return PgCard(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      onTap: onTap,
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.sm + 2,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  productionOrderListNo(order),
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
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
          const SizedBox(height: 4),
          Text(
            order['dealer_name']?.toString() ?? '-',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          Text(
            order['dealer_village']?.toString() ?? '-',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
          ),
          const SizedBox(height: 2),
          Text(
            _displayDate(),
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textMuted,
                ),
          ),
        ],
      ),
    );
  }
}
