import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/order_api.dart';
import '../models/order_dashboard_data.dart';
import '../models/order_filter.dart';
import '../widgets/order_widgets.dart';

class OrderDashboardScreen extends StatefulWidget {
  const OrderDashboardScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<OrderDashboardScreen> createState() => _OrderDashboardScreenState();
}

class _OrderDashboardScreenState extends State<OrderDashboardScreen> {
  late Future<OrderDashboardData> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<OrderDashboardData> _load() => OrderApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  ).loadDashboard();

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _openFilter(OrderFilter filter) async {
    await context.push('/orders/list/${filter.name}');
    if (!mounted) return;
    await _reload();
  }

  Future<void> _openOrder(int orderId) async {
    await context.push('/orders/$orderId');
    if (!mounted) return;
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      auth: widget.auth,
      title: 'Orders',
      showBack: true,
      backFallback: '/dashboard',
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push('/orders/new'),
        icon: const Icon(Icons.add_rounded),
        label: const Text('New Order'),
      ),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<OrderDashboardData>(
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
                    message: 'Unable to load orders.',
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final data = snapshot.data ?? OrderDashboardData.empty;

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                LayoutBuilder(
                  builder: (context, constraints) => GridView.count(
                    crossAxisCount: constraints.maxWidth >= 700 ? 4 : 2,
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    childAspectRatio: constraints.maxWidth >= 700 ? 1.8 : 1.45,
                    mainAxisSpacing: AppSpacing.sm,
                    crossAxisSpacing: AppSpacing.sm,
                    children: [
                      OrderSummaryCard(
                        label: 'Total Orders',
                        value: '${data.totalOrders}',
                        color: AppColors.primary,
                        icon: const Icon(Icons.receipt_long_rounded),
                        onTap: () => _openFilter(OrderFilter.all),
                      ),
                      OrderSummaryCard(
                        label: 'Pending Orders',
                        value: '${data.pendingOrders}',
                        color: AppColors.warning,
                        icon: const Icon(Icons.pending_actions_rounded),
                        onTap: () => _openFilter(OrderFilter.pending),
                      ),
                      OrderSummaryCard(
                        label: 'Dispatched Orders',
                        value: '${data.dispatchedOrders}',
                        color: AppColors.info,
                        icon: const Icon(Icons.local_shipping_rounded),
                        onTap: () => _openFilter(OrderFilter.dispatched),
                      ),
                      OrderSummaryCard(
                        label: 'Rejected Orders',
                        value: '${data.rejectedOrders}',
                        color: AppColors.error,
                        icon: const Icon(Icons.cancel_outlined),
                        onTap: () => _openFilter(OrderFilter.rejected),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),
                const PgSectionHeader(title: 'Recent Orders'),
                if (data.recentOrders.isEmpty)
                  const PgEmptyState(
                    message: 'No recent orders found.',
                    icon: const Icon(Icons.receipt_long_outlined),
                  )
                else
                  ...data.recentOrders.map(
                    (order) => RecentOrderTile(
                      order: order,
                      onTap: order.id == null
                          ? null
                          : () => _openOrder(order.id!),
                    ),
                  ),
                const SizedBox(height: 80),
              ],
            );
          },
        ),
      ),
    );
  }
}
