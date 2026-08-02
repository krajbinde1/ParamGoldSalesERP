import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/api/api_errors.dart';
import '../../../../core/design/app_spacing.dart';
import '../../../../core/storage/session_store.dart';
import '../../../../core/widgets/design/pg_card.dart';
import '../../../../core/widgets/design/pg_empty_state.dart';
import '../../../../core/widgets/role_shell_widgets.dart';
import '../../../auth/providers/auth_controller.dart';
import '../../api/inventory_production_api.dart';

class MaterialShortageScreen extends StatefulWidget {
  const MaterialShortageScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<MaterialShortageScreen> createState() => _MaterialShortageScreenState();
}

class _MaterialShortageScreenState extends State<MaterialShortageScreen> {
  late Future<List<Map<String, dynamic>>> _future;

  InventoryProductionApi get _api => InventoryProductionApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  @override
  void initState() {
    super.initState();
    _future = _api.shortages();
  }

  Future<void> _reload() async {
    setState(() => _future = _api.shortages());
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'Material Shortage', auth: widget.auth),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<List<Map<String, dynamic>>>(
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
            final items = snapshot.data ?? const [];
            if (items.isEmpty) {
              return const PgEmptyState(message: 'No shortage items.');
            }
            return ListView.builder(
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              itemCount: items.length,
              itemBuilder: (context, index) {
                final item = items[index];
                return PgCard(
                  margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${item['material_name'] ?? item['packaging_name']}',
                        style: Theme.of(context).textTheme.titleSmall,
                      ),
                      Text('${item['item_type']} • ${item['stock_status']}'),
                      Text(
                        'Stock ${item['current_stock']} / Min ${item['minimum_stock']}',
                      ),
                    ],
                  ),
                );
              },
            );
          },
        ),
      ),
    );
  }
}

class ProductionHistoryScreen extends StatefulWidget {
  const ProductionHistoryScreen({
    super.key,
    required this.auth,
    this.initialFrom,
    this.initialTo,
  });

  final AuthController auth;
  final String? initialFrom;
  final String? initialTo;

  @override
  State<ProductionHistoryScreen> createState() =>
      _ProductionHistoryScreenState();
}

class _ProductionHistoryScreenState extends State<ProductionHistoryScreen> {
  final _batch = TextEditingController();
  String? _outputType;
  String? _status;
  DateTime? _from;
  DateTime? _to;
  late Future<Map<String, dynamic>> _future;

  InventoryProductionApi get _api => InventoryProductionApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  @override
  void initState() {
    super.initState();
    _from = _parseDate(widget.initialFrom);
    _to = _parseDate(widget.initialTo);
    _future = _load();
  }

  DateTime? _parseDate(String? value) {
    if (value == null || value.isEmpty) return null;
    return DateTime.tryParse(value);
  }

  @override
  void dispose() {
    _batch.dispose();
    super.dispose();
  }

  Future<Map<String, dynamic>> _load() => _api.history(
        outputType: _outputType,
        batchNumber: _batch.text.trim(),
        from: _from?.toIso8601String().substring(0, 10),
        to: _to?.toIso8601String().substring(0, 10),
        status: _status,
      );

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _pickFrom() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _from ?? DateTime.now().subtract(const Duration(days: 30)),
      firstDate: DateTime(2020),
      lastDate: _to ?? DateTime.now().add(const Duration(days: 1)),
    );
    if (picked != null) {
      setState(() => _from = picked);
      await _reload();
    }
  }

  Future<void> _pickTo() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _to ?? DateTime.now(),
      firstDate: _from ?? DateTime(2020),
      lastDate: DateTime.now().add(const Duration(days: 1)),
    );
    if (picked != null) {
      setState(() => _to = picked);
      await _reload();
    }
  }

  @override
  Widget build(BuildContext context) {
    final canCosts = widget.auth.permissions.canViewProductionCosts;

    return Scaffold(
      appBar: RoleAppBar(title: 'Production History', auth: widget.auth),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            child: Column(
              children: [
                Row(
                  children: [
                    Expanded(
                      child: DropdownButtonFormField<String?>(
                        value: _outputType,
                        isExpanded: true,
                        decoration:
                            const InputDecoration(labelText: 'Type'),
                        items: const [
                          DropdownMenuItem(value: null, child: Text('All')),
                          DropdownMenuItem(
                            value: 'semi_finished',
                            child: Text('Semi-Finished'),
                          ),
                          DropdownMenuItem(
                            value: 'finished_product',
                            child: Text('Finished Product'),
                          ),
                        ],
                        onChanged: (v) {
                          setState(() => _outputType = v);
                          _reload();
                        },
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: DropdownButtonFormField<String?>(
                        value: _status,
                        isExpanded: true,
                        decoration:
                            const InputDecoration(labelText: 'Status'),
                        items: const [
                          DropdownMenuItem(
                            value: null,
                            child: Text('Completed'),
                          ),
                          DropdownMenuItem(
                            value: 'completed',
                            child: Text('Completed'),
                          ),
                          DropdownMenuItem(
                            value: 'in_production',
                            child: Text('In production'),
                          ),
                          DropdownMenuItem(
                            value: 'draft',
                            child: Text('Draft'),
                          ),
                        ],
                        onChanged: (v) {
                          setState(() => _status = v);
                          _reload();
                        },
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: _batch,
                  decoration: InputDecoration(
                    labelText: 'Batch / product search',
                    suffixIcon: IconButton(
                      icon: const Icon(Icons.search),
                      onPressed: _reload,
                    ),
                  ),
                  onSubmitted: (_) => _reload(),
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton(
                        onPressed: _pickFrom,
                        child: Text(
                          _from == null
                              ? 'From date'
                              : 'From ${_from!.toIso8601String().substring(0, 10)}',
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: OutlinedButton(
                        onPressed: _pickTo,
                        child: Text(
                          _to == null
                              ? 'To date'
                              : 'To ${_to!.toIso8601String().substring(0, 10)}',
                        ),
                      ),
                    ),
                  ],
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
                    return const PgEmptyState(
                      message: 'No production batches match filters.',
                    );
                  }
                  return ListView.builder(
                    padding: const EdgeInsets.all(AppSpacing.screenPadding),
                    itemCount: items.length,
                    itemBuilder: (context, index) {
                      final batch = items[index];
                      final id = int.tryParse('${batch['id']}') ?? 0;
                      return PgCard(
                        margin:
                            const EdgeInsets.only(bottom: AppSpacing.sm),
                        onTap: id > 0
                            ? () =>
                                context.push('/production/batches/$id')
                            : null,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              '${batch['batch_number']}',
                              style:
                                  Theme.of(context).textTheme.titleSmall,
                            ),
                            Text(
                              '${batch['output_type'] ?? '-'} · ${batch['product_name'] ?? batch['output_item_name'] ?? '-'}',
                            ),
                            Text(
                              '${batch['production_date']} · Output ${batch['production_quantity'] ?? batch['actual_output_quantity'] ?? '-'} · ${batch['status_label'] ?? batch['status'] ?? ''}',
                            ),
                            if (canCosts &&
                                batch['total_batch_cost'] != null)
                              Text('Cost ₹${batch['total_batch_cost']}'),
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
