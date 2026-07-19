import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/production_api.dart';

class ProductionDashboardScreen extends StatefulWidget {
  const ProductionDashboardScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<ProductionDashboardScreen> createState() =>
      _ProductionDashboardScreenState();
}

class _ProductionDashboardScreenState extends State<ProductionDashboardScreen> {
  late Future<ProductionDashboardData> _future;

  @override
  void initState() {
    super.initState();
    _future = ProductionApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
          .dio,
    ).loadDashboard();
  }

  Future<void> _reload() async {
    setState(() {
      _future = ProductionApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      ).loadDashboard();
    });
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'Production Dashboard', auth: widget.auth),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<ProductionDashboardData>(
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
                    message: errorMessage(snapshot.error),
                    onRetry: _reload,
                  ),
                ],
              );
            }
            final data = snapshot.data!;
            return ListView(
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                Wrap(
                  spacing: 12,
                  runSpacing: 12,
                  children: [
                    DashboardMetricCard(
                      label: 'Approved Orders',
                      value: '${data.approvedOrders}',
                    ),
                    DashboardMetricCard(
                      label: 'Ready for Dispatch',
                      value: '${data.readyForDispatch}',
                    ),
                    DashboardMetricCard(
                      label: 'Dispatched',
                      value: '${data.dispatchedOrders}',
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.lg),
                const PgSectionHeader(title: 'Modules'),
                const SizedBox(height: AppSpacing.sm),
                ModuleTile(
                  icon: Icons.local_shipping_outlined,
                  label: 'Production Orders',
                  subtitle: 'Approved and dispatched orders',
                  onTap: () => context.push('/production/orders'),
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}

class ProductionOrdersScreen extends StatefulWidget {
  const ProductionOrdersScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<ProductionOrdersScreen> createState() => _ProductionOrdersScreenState();
}

class _ProductionOrdersScreenState extends State<ProductionOrdersScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  late Future<List<Map<String, dynamic>>> _approvedFuture;
  late Future<List<Map<String, dynamic>>> _dispatchedFuture;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    final api = ProductionApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
          .dio,
    );
    _approvedFuture = api.listOrders(status: 'approved');
    _dispatchedFuture = api.listOrders(status: 'dispatched');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Production Orders'),
        bottom: TabBar(
          controller: _tabController,
          tabs: const [
            Tab(text: 'Approved'),
            Tab(text: 'Dispatched'),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabController,
        children: [
          _OrderList(
            future: _approvedFuture,
            onTap: (id) => context.push('/production/orders/$id'),
          ),
          _OrderList(
            future: _dispatchedFuture,
            onTap: (id) => context.push('/production/orders/$id'),
            readOnly: true,
          ),
        ],
      ),
    );
  }
}

class _OrderList extends StatelessWidget {
  const _OrderList({
    required this.future,
    required this.onTap,
    this.readOnly = false,
  });

  final Future<List<Map<String, dynamic>>> future;
  final void Function(int id) onTap;
  final bool readOnly;

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<Map<String, dynamic>>>(
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
          return PgEmptyState(
            message: readOnly ? 'No dispatched orders.' : 'No approved orders.',
          );
        }
        return ListView.builder(
          padding: const EdgeInsets.all(AppSpacing.screenPadding),
          itemCount: orders.length,
          itemBuilder: (context, index) {
            final order = orders[index];
            return PgCard(
              margin: const EdgeInsets.only(bottom: AppSpacing.sm),
              onTap: () => onTap(int.tryParse('${order['id'] ?? 0}') ?? 0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    order['order_no']?.toString() ?? '-',
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                  Text(
                    '${order['dealer_name'] ?? '-'} • ${order['employee_name'] ?? '-'}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }
}
