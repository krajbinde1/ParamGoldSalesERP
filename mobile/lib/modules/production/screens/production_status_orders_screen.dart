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
        'rejected' => 'Rejected Orders',
        _ => 'Orders',
      };

  String get _emptyMessage => switch (widget.status) {
        'approved' => 'No approved orders.',
        'sent_for_bill' => 'No orders sent for billing.',
        'billed' => 'No billed orders.',
        'dispatched' => 'No dispatched orders.',
        'rejected' => 'No rejected orders.',
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

  Future<void> _openOrder(int id) async {
    final result = await context.push<Object?>('/production/orders/$id');
    if (!mounted) return;
    await _refresh();
    if (!mounted) return;
    if (result == 'dispatched' && widget.status == 'billed') {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Order moved to Dispatched.')),
      );
    }
  }

  Future<void> _dispatchFromList(Map<String, dynamic> order) async {
    final ok = await confirmAndDispatchProductionOrder(
      context: context,
      api: _api,
      order: order,
    );
    if (ok && mounted) await _refresh();
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
                      final canDispatchThis =
                          widget.auth.permissions.canDispatchOrders &&
                              widget.status == 'billed' &&
                              order['can_dispatch'] == true;

                      return ProductionOrderListCard(
                        order: order,
                        statusKey: widget.status,
                        currency: currency,
                        dateTime: dateTime,
                        onTap: () {
                          if (id > 0) _openOrder(id);
                        },
                        showDispatchAction: canDispatchThis,
                        onDispatch: canDispatchThis
                            ? () => _dispatchFromList(order)
                            : null,
                      );
                    },
                  ),
          );
        },
      ),
    );
  }
}
