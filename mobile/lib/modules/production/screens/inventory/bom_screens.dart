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

class BomListScreen extends StatefulWidget {
  const BomListScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<BomListScreen> createState() => _BomListScreenState();
}

class _BomListScreenState extends State<BomListScreen> {
  final _search = TextEditingController();
  String? _outputType;
  late Future<Map<String, dynamic>> _future;

  InventoryProductionApi get _api => InventoryProductionApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<Map<String, dynamic>> _load() => _api.listBoms(
        search: _search.text.trim(),
        outputType: _outputType,
      );

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'Bill of Materials', auth: widget.auth),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            child: Column(
              children: [
                TextField(
                  controller: _search,
                  decoration: InputDecoration(
                    hintText: 'Search output item or BOM no.',
                    suffixIcon: IconButton(
                      icon: const Icon(Icons.search),
                      onPressed: _reload,
                    ),
                  ),
                  onSubmitted: (_) => _reload(),
                ),
                const SizedBox(height: 8),
                DropdownButtonFormField<String?>(
                  value: _outputType,
                  decoration: const InputDecoration(labelText: 'Output type'),
                  items: const [
                    DropdownMenuItem(value: null, child: Text('All')),
                    DropdownMenuItem(
                      value: 'finished_product',
                      child: Text('Finished Goods (FG)'),
                    ),
                    DropdownMenuItem(
                      value: 'semi_finished',
                      child: Text('Semi-Finished (SF)'),
                    ),
                  ],
                  onChanged: (v) {
                    setState(() => _outputType = v);
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
                    return const PgEmptyState(message: 'No active BOMs found.');
                  }
                  return ListView.builder(
                    padding: const EdgeInsets.all(AppSpacing.screenPadding),
                    itemCount: items.length,
                    itemBuilder: (context, index) {
                      final bom = items[index];
                      return PgCard(
                        margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                        onTap: () => context.push(
                          '/production/bom/${bom['id']}',
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Expanded(
                                  child: Text(
                                    '${bom['output_item_name'] ?? '-'}',
                                    style:
                                        Theme.of(context).textTheme.titleSmall,
                                  ),
                                ),
                                PgStatusBadge(
                                  label:
                                      '${bom['status_label'] ?? bom['status'] ?? 'Active'}',
                                ),
                              ],
                            ),
                            Text(
                              'Type: ${bom['output_type_label'] ?? bom['output_type']}',
                            ),
                            Text(
                              'Formula: ${bom['formula_quantity_label'] ?? '${bom['batch_quantity']} ${bom['batch_unit'] ?? ''}'.trim()}',
                            ),
                            if (bom['effective_date'] != null)
                              Text('Effective: ${bom['effective_date']}'),
                          ],
                        ),
                      );
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
}

class BomDetailScreen extends StatefulWidget {
  const BomDetailScreen({super.key, required this.auth, required this.bomId});
  final AuthController auth;
  final int bomId;

  @override
  State<BomDetailScreen> createState() => _BomDetailScreenState();
}

class _BomDetailScreenState extends State<BomDetailScreen> {
  late Future<Map<String, dynamic>> _future;

  InventoryProductionApi get _api => InventoryProductionApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  @override
  void initState() {
    super.initState();
    _future = _api.bomDetail(widget.bomId);
  }

  Future<void> _reload() async {
    setState(() => _future = _api.bomDetail(widget.bomId));
    await _future;
  }

  String _typeLabel(String? type) => switch (type) {
        'raw_material' => 'RM',
        'packaging_material' => 'Packaging',
        'semi_finished' => 'SF',
        _ => type ?? '-',
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'BOM Details', auth: widget.auth),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<Map<String, dynamic>>(
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
            final bom = Map<String, dynamic>.from(data['bom'] as Map? ?? {});
            final items = (data['items'] as List?)
                    ?.map((e) => Map<String, dynamic>.from(e as Map))
                    .toList() ??
                const [];

            return ListView(
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                PgCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${bom['output_item_name'] ?? '-'}',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      Text(
                        'Type: ${bom['output_type_label'] ?? bom['output_type']}',
                      ),
                      Text(
                        'Formula: ${bom['formula_quantity_label'] ?? '${bom['batch_quantity']} ${bom['batch_unit'] ?? ''}'.trim()}',
                      ),
                      if (bom['effective_date'] != null)
                        Text('Effective: ${bom['effective_date']}'),
                      Text('Status: ${bom['status_label'] ?? bom['status']}'),
                      if (bom['bom_number'] != null)
                        Text('BOM No: ${bom['bom_number']}'),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                Text(
                  'Formula Items',
                  style: Theme.of(context).textTheme.titleSmall,
                ),
                const SizedBox(height: AppSpacing.sm),
                if (items.isEmpty)
                  const PgEmptyState(message: 'No formula items.')
                else
                  ...items.map((item) {
                    final formQty = item['required_quantity'];
                    final formUnit =
                        item['formulation_unit'] ?? item['unit'] ?? '';
                    final invQty = item['inventory_equivalent_quantity'];
                    final invUnit = item['inventory_unit'] ?? formUnit;
                    return PgCard(
                      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            '${item['material_name']}',
                            style: Theme.of(context).textTheme.titleSmall,
                          ),
                          Text('Type: ${_typeLabel('${item['item_type']}')}'),
                          Text('Formulation: $formQty $formUnit'),
                          if (invQty != null)
                            Text('Inventory eq.: $invQty $invUnit'),
                        ],
                      ),
                    );
                  }),
              ],
            );
          },
        ),
      ),
    );
  }
}
