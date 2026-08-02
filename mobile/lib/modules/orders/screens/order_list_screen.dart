import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../../core/widgets/design/pg_search_bar.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/order_api.dart';
import '../models/order.dart';
import '../models/order_filter.dart';
import '../widgets/order_widgets.dart';

class OrderListScreen extends StatefulWidget {
  const OrderListScreen({super.key, required this.filter, required this.auth});
  final OrderFilter filter;
  final AuthController auth;

  @override
  State<OrderListScreen> createState() => _OrderListScreenState();
}

class _OrderListScreenState extends State<OrderListScreen> {
  late Future<List<Order>> _future;
  final _searchController = TextEditingController();
  String _searchQuery = '';
  late OrderFilter _activeFilter;

  @override
  void initState() {
    super.initState();
    _activeFilter = widget.filter;
    _future = _load();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<List<Order>> _load() => OrderApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  ).listOrders(_activeFilter);

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  void _onFilterSelected(String value) {
    final filter = OrderFilter.values.firstWhere(
      (f) => f.name == value,
      orElse: () => OrderFilter.all,
    );
    setState(() {
      _activeFilter = filter;
      _future = _load();
    });
  }

  List<Order> _filterBySearch(List<Order> orders) {
    if (_searchQuery.isEmpty) return orders;
    final q = _searchQuery.toLowerCase();
    return orders.where((o) {
      return o.orderNo.toLowerCase().contains(q) ||
          o.dealerName.toLowerCase().contains(q);
    }).toList();
  }

  Future<void> _openOrder(Order order) async {
    if (order.id == null) return;
    await context.push('/orders/${order.id}');
    if (!mounted) return;
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    final filterOptions = OrderFilter.values
        .map((f) => (f.title, f.name))
        .toList();

    return PgPageScaffold(
      title: _activeFilter.title,
      showBack: true,
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.screenPadding,
              AppSpacing.sm,
              AppSpacing.screenPadding,
              AppSpacing.sm,
            ),
            child: PgSearchBar(
              controller: _searchController,
              hint: 'Search orders...',
              onChanged: (value) => setState(() => _searchQuery = value),
              onClear: () => setState(() => _searchQuery = ''),
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.screenPadding,
            ),
            child: PgFilterChips(
              options: filterOptions,
              selected: _activeFilter.name,
              onSelected: _onFilterSelected,
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _reload,
              child: FutureBuilder<List<Order>>(
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

                  final orders = _filterBySearch(snapshot.data ?? const []);

                  if (orders.isEmpty) {
                    return ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.all(AppSpacing.screenPadding),
                      children: const [
                        PgEmptyState(
                          message: 'No orders found.',
                          icon: const Icon(Icons.receipt_long_outlined),
                        ),
                      ],
                    );
                  }

                  return ListView.builder(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(AppSpacing.screenPadding),
                    itemCount: orders.length,
                    itemBuilder: (context, index) => OrderListTile(
                      order: orders[index],
                      onTap: () => _openOrder(orders[index]),
                    ),
                  );
                },
              ),
            ),
          ),
        ],
      ),
    );
  }
}
