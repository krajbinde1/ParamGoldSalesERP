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
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../../orders/models/order.dart';
import '../api/production_api.dart';

/// Dedicated Production Supervisor list for one order status.
class ProductionStatusOrdersScreen extends StatefulWidget {
  const ProductionStatusOrdersScreen({
    super.key,
    required this.auth,
    required this.status,
  });

  final AuthController auth;
  final String status;

  @override
  State<ProductionStatusOrdersScreen> createState() =>
      _ProductionStatusOrdersScreenState();
}

class _ProductionStatusOrdersScreenState
    extends State<ProductionStatusOrdersScreen> {
  late ProductionApi _api;
  late Future<List<Map<String, dynamic>>> _future;

  String get _title => switch (widget.status) {
        'approved' => 'Approved Orders',
        'sent_for_bill' => 'Sent for Bill Orders',
        'billed' => 'Billed Orders',
        'dispatched' => 'Dispatched Orders',
        _ => 'Orders',
      };

  String get _emptyMessage => switch (widget.status) {
        'approved' => 'No approved orders.',
        'sent_for_bill' => 'No orders sent for billing.',
        'billed' => 'No billed orders.',
        'dispatched' => 'No dispatched orders.',
        _ => 'No orders.',
      };

  @override
  void initState() {
    super.initState();
    _api = ProductionApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
          .dio,
    );
    _future = _load();
  }

  Future<List<Map<String, dynamic>>> _load() async {
    if (widget.status == 'approved') {
      final primary = await _api.listOrders(status: 'approved');
      if (primary.orders.isNotEmpty) return primary.orders;

      final all = await _api.listOrders();
      return all.orders
          .where(
            (order) => ProductionApi.isApprovedTabStatus(
              order['status']?.toString(),
            ),
          )
          .toList(growable: false);
    }

    final result = await _api.listOrders(status: widget.status);
    return result.orders;
  }

  Future<void> _refresh() async {
    final next = _load();
    setState(() => _future = next);
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
      appBar: RoleAppBar(title: _title, auth: widget.auth),
      body: FutureBuilder<List<Map<String, dynamic>>>(
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

          final orders = snapshot.data ?? const [];
          return RefreshIndicator(
            onRefresh: _refresh,
            child: orders.isEmpty
                ? ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    children: [PgEmptyState(message: _emptyMessage)],
                  )
                : ListView.builder(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(AppSpacing.screenPadding),
                    itemCount: orders.length,
                    itemBuilder: (context, index) {
                      final order = orders[index];
                      final id = int.tryParse('${order['id'] ?? 0}') ?? 0;
                      return _StatusOrderCard(
                        order: order,
                        statusKey: widget.status,
                        currency: currency,
                        dateTime: dateTime,
                        onTap: () async {
                          if (id <= 0) return;
                          await context.push('/production/orders/$id');
                          if (mounted) await _refresh();
                        },
                      );
                    },
                  ),
          );
        },
      ),
    );
  }
}

class _StatusOrderCard extends StatelessWidget {
  const _StatusOrderCard({
    required this.order,
    required this.statusKey,
    required this.currency,
    required this.dateTime,
    required this.onTap,
  });

  final Map<String, dynamic> order;
  final String statusKey;
  final NumberFormat currency;
  final DateFormat dateTime;
  final VoidCallback onTap;

  String _formatDateTime(Object? raw) {
    if (raw == null) return '-';
    final parsed = DateTime.tryParse(raw.toString());
    if (parsed == null) return raw.toString();
    return dateTime.format(parsed.toLocal());
  }

  @override
  Widget build(BuildContext context) {
    final amount = double.tryParse('${order['grand_total'] ?? 0}') ?? 0;
    final status = order['status']?.toString() ?? '';
    final freight = double.tryParse(
      '${order['transport_amount'] ?? order['transport_freight'] ?? ''}',
    );

    return PgCard(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      onTap: onTap,
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
          if (statusKey == 'dispatched') ...[
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
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
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
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
            ),
            if ((order['payment_type']?.toString() ?? '').isNotEmpty)
              Text(
                'Payment: ${order['payment_type']}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: AppColors.textSecondary,
                    ),
              ),
            if (statusKey != 'approved') ...[
              if ((order['vehicle_number']?.toString() ?? '').isNotEmpty)
                Text(
                  'Vehicle: ${order['vehicle_number']}',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AppColors.textSecondary,
                      ),
                ),
              if (freight != null)
                Text(
                  'Freight: ${currency.format(freight)}',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AppColors.textSecondary,
                      ),
                ),
            ],
            if (statusKey == 'sent_for_bill')
              Text(
                'Sent: ${_formatDateTime(order['sent_for_bill_at'])}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: AppColors.textSecondary,
                    ),
              ),
            if (statusKey == 'billed') ...[
              if ((order['bill_number']?.toString() ?? '').isNotEmpty)
                Text(
                  'Bill No: ${order['bill_number']}',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AppColors.textSecondary,
                      ),
                ),
              Text(
                'Bill Date: ${order['bill_date'] ?? '-'}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: AppColors.textSecondary,
                    ),
              ),
              Text(
                'Billed: ${_formatDateTime(order['billed_at'])}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: AppColors.textSecondary,
                    ),
              ),
            ],
          ],
        ],
      ),
    );
  }
}
