import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/api/api_errors.dart';
import '../../../../core/design/app_spacing.dart';
import '../../../../core/storage/session_store.dart';
import '../../../../core/widgets/design/pg_card.dart';
import '../../../../core/widgets/design/pg_empty_state.dart';
import '../../../../core/widgets/design/pg_status_badge.dart';
import '../../../../core/widgets/role_shell_widgets.dart';
import '../../../auth/providers/auth_controller.dart';
import '../../api/inventory_production_api.dart';

enum StockListType { packaging, semiFinished, finished }

/// Packaging / Semi-Finished / Finished master-ish lists (not RM stock report).
class StockListScreen extends StatefulWidget {
  const StockListScreen({
    super.key,
    required this.auth,
    required this.type,
    this.initialStockStatus,
  });

  final AuthController auth;
  final StockListType type;
  final String? initialStockStatus;

  @override
  State<StockListScreen> createState() => _StockListScreenState();
}

class _StockListScreenState extends State<StockListScreen> {
  final _search = TextEditingController();
  String? _stockStatus;
  late Future<Map<String, dynamic>> _future;

  InventoryProductionApi get _api => InventoryProductionApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  String get _title => switch (widget.type) {
        StockListType.packaging => 'Packaging Material Master',
        StockListType.semiFinished => 'Semi-Finished Master',
        StockListType.finished => 'Finished Products',
      };

  String get _itemType => switch (widget.type) {
        StockListType.packaging => 'packaging_material',
        StockListType.semiFinished => 'semi_finished',
        StockListType.finished => 'finished_product',
      };

  @override
  void initState() {
    super.initState();
    _stockStatus = widget.initialStockStatus;
    _future = _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<Map<String, dynamic>> _load() {
    return switch (widget.type) {
      StockListType.packaging => _api.listPackagingMaterials(
          search: _search.text.trim(),
          stockStatus: _stockStatus,
        ),
      StockListType.semiFinished => _api.listSemiFinishedMaterials(
          search: _search.text.trim(),
          stockStatus: _stockStatus,
        ),
      StockListType.finished => _api.listFinishedGoods(
          search: _search.text.trim(),
          stockStatus: _stockStatus,
        ),
    };
  }

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  String _statusLabel(String status) => switch (status) {
        'available' || 'in_stock' => 'In Stock',
        'low' || 'low_stock' => 'Low Stock',
        'out' || 'out_of_stock' || 'shortage' => 'Out of Stock',
        _ => status.replaceAll('_', ' '),
      };

  void _openLedger(Map<String, dynamic> item) {
    final id = int.tryParse('${item['id']}') ?? 0;
    if (id <= 0) return;
    context.push('/production/ledger?type=$_itemType&id=$id');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: _title, auth: widget.auth),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            child: Column(
              children: [
                TextField(
                  controller: _search,
                  decoration: InputDecoration(
                    hintText: 'Search name or code',
                    suffixIcon: IconButton(
                      icon: const Icon(Icons.search),
                      onPressed: _reload,
                    ),
                  ),
                  onSubmitted: (_) => _reload(),
                ),
                const SizedBox(height: 8),
                DropdownButtonFormField<String?>(
                  value: _stockStatus,
                  decoration: const InputDecoration(labelText: 'Stock status'),
                  items: const [
                    DropdownMenuItem(value: null, child: Text('All')),
                    DropdownMenuItem(
                      value: 'available',
                      child: Text('In Stock'),
                    ),
                    DropdownMenuItem(value: 'low', child: Text('Low stock')),
                    DropdownMenuItem(
                      value: 'out',
                      child: Text('Out of stock'),
                    ),
                  ],
                  onChanged: (value) {
                    setState(() => _stockStatus = value);
                    _reload();
                  },
                ),
              ],
            ),
          ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _reload,
              child: FutureBuilder<Map<String, dynamic>>(
                future: _future,
                builder: (context, snapshot) {
                  if (snapshot.connectionState == ConnectionState.waiting &&
                      !snapshot.hasData) {
                    return const PgLoadingState();
                  }
                  if (snapshot.hasError) {
                    return PgErrorState(
                      message: errorMessage(snapshot.error),
                      onRetry: _reload,
                    );
                  }
                  final items = (snapshot.data?['data'] as List?)
                          ?.map((e) => Map<String, dynamic>.from(e as Map))
                          .toList() ??
                      const [];
                  if (items.isEmpty) {
                    return const PgEmptyState(message: 'No items found.');
                  }
                  return ListView.builder(
                    padding: const EdgeInsets.all(AppSpacing.screenPadding),
                    itemCount: items.length,
                    itemBuilder: (context, index) {
                      final item = items[index];
                      return switch (widget.type) {
                        StockListType.semiFinished => _sfCard(item),
                        StockListType.finished => _fgCard(item),
                        StockListType.packaging => _packCard(item),
                      };
                    },
                  );
                },
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _statusBadge(String status) {
    if (status.isEmpty) return const SizedBox.shrink();
    return PgStatusBadge(
      label: _statusLabel(status),
      tone: switch (status) {
        'available' || 'in_stock' => PgStatusTone.approved,
        'low' || 'low_stock' => PgStatusTone.pending,
        'out' || 'out_of_stock' || 'shortage' => PgStatusTone.rejected,
        _ => PgStatusTone.neutral,
      },
    );
  }

  Widget _packCard(Map<String, dynamic> item) {
    final name = item['packaging_name'] ?? item['material_name'] ?? '-';
    final code = item['packaging_code'] ?? item['material_code'] ?? '-';
    final unit = item['unit'] ?? '';
    final status = item['status'] == true || item['status'] == 1
        ? 'Active'
        : (item['stock_status']?.toString() ?? '');

    return PgCard(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('$name', style: Theme.of(context).textTheme.titleSmall),
          Text('Code: $code'),
          Text('Unit: $unit'),
          if (status.isNotEmpty) Text('Status: $status'),
        ],
      ),
    );
  }

  Widget _sfCard(Map<String, dynamic> item) {
    final name = item['material_name'] ?? '-';
    final code = item['material_code'] ?? '-';
    final unit = item['unit'] ?? '';
    final status = item['stock_status']?.toString() ?? '';

    return PgCard(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      onTap: () => _openLedger(item),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '$name',
                  style: Theme.of(context).textTheme.titleSmall,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              _statusBadge(status),
            ],
          ),
          Text('Code: $code'),
          Text('Unit: $unit'),
        ],
      ),
    );
  }

  Widget _fgCard(Map<String, dynamic> item) {
    final name = item['product_name'] ?? '-';
    final code = item['product_code'] ?? '-';
    final unit = item['unit'] ?? item['production_unit'] ?? '';
    final status = item['stock_status']?.toString() ?? '';

    return PgCard(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      onTap: () => _openLedger(item),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '$name',
                  style: Theme.of(context).textTheme.titleSmall,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              _statusBadge(status),
            ],
          ),
          Text('Code: $code'),
          Text('Unit: $unit'),
        ],
      ),
    );
  }
}
