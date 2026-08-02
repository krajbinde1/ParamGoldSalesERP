import 'package:flutter/material.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/api/api_errors.dart';
import '../../../../core/design/app_spacing.dart';
import '../../../../core/storage/session_store.dart';
import '../../../../core/widgets/design/pg_card.dart';
import '../../../../core/widgets/design/pg_empty_state.dart';
import '../../../../core/widgets/role_shell_widgets.dart';
import '../../../auth/providers/auth_controller.dart';
import '../../api/inventory_production_api.dart';

/// Browsable stock ledger for Production Supervisor Inventory tab.
class StockLedgerBrowseScreen extends StatefulWidget {
  const StockLedgerBrowseScreen({
    super.key,
    required this.auth,
    this.initialItemType,
    this.initialTxnType,
  });

  final AuthController auth;
  final String? initialItemType;
  final String? initialTxnType;

  @override
  State<StockLedgerBrowseScreen> createState() =>
      _StockLedgerBrowseScreenState();
}

class _StockLedgerBrowseScreenState extends State<StockLedgerBrowseScreen> {
  final _search = TextEditingController();
  final _batch = TextEditingController();
  String? _itemType;
  String? _txnType;
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
    _itemType = widget.initialItemType;
    _txnType = widget.initialTxnType;
    _future = _load();
  }

  @override
  void dispose() {
    _search.dispose();
    _batch.dispose();
    super.dispose();
  }

  Future<Map<String, dynamic>> _load() => _api.stockLedgerBrowse(
        itemType: _itemType,
        transactionType: _txnType,
        search: _search.text.trim(),
        batchNumber: _batch.text.trim(),
        from: _from?.toIso8601String().substring(0, 10),
        to: _to?.toIso8601String().substring(0, 10),
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
    return Scaffold(
      appBar: RoleAppBar(title: 'Stock Ledger', auth: widget.auth),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            child: Column(
              children: [
                TextField(
                  controller: _search,
                  decoration: InputDecoration(
                    hintText: 'Item name',
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
                      child: DropdownButtonFormField<String?>(
                        value: _itemType,
                        isExpanded: true,
                        decoration:
                            const InputDecoration(labelText: 'Item type'),
                        items: const [
                          DropdownMenuItem(value: null, child: Text('All')),
                          DropdownMenuItem(
                            value: 'raw_material',
                            child: Text('Raw Material'),
                          ),
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
                          setState(() => _itemType = v);
                          _reload();
                        },
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: DropdownButtonFormField<String?>(
                        value: _txnType,
                        isExpanded: true,
                        decoration:
                            const InputDecoration(labelText: 'Txn type'),
                        items: const [
                          DropdownMenuItem(value: null, child: Text('All')),
                          DropdownMenuItem(
                            value: 'opening_stock',
                            child: Text('Opening'),
                          ),
                          DropdownMenuItem(
                            value: 'raw_material_inward',
                            child: Text('RM Inward'),
                          ),
                          DropdownMenuItem(
                            value: 'production_consumption',
                            child: Text('Production Out'),
                          ),
                          DropdownMenuItem(
                            value: 'production_output',
                            child: Text('FG Production'),
                          ),
                          DropdownMenuItem(
                            value: 'semi_finished_production',
                            child: Text('SF Production'),
                          ),
                          DropdownMenuItem(
                            value: 'dispatch',
                            child: Text('Dispatch'),
                          ),
                          DropdownMenuItem(
                            value: 'stock_adjustment',
                            child: Text('Adjustment'),
                          ),
                        ],
                        onChanged: (v) {
                          setState(() => _txnType = v);
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
                    labelText: 'Batch number',
                    suffixIcon: IconButton(
                      icon: const Icon(Icons.filter_alt),
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
                  final rows = (snapshot.data?['data'] as List?)
                          ?.map((e) => Map<String, dynamic>.from(e as Map))
                          .toList() ??
                      const [];
                  if (rows.isEmpty) {
                    return const PgEmptyState(
                      message: 'No ledger movements found.',
                    );
                  }
                  return ListView.builder(
                    padding: const EdgeInsets.all(AppSpacing.screenPadding),
                    itemCount: rows.length,
                    itemBuilder: (context, index) {
                      final row = rows[index];
                      return PgCard(
                        margin:
                            const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              '${row['transaction_date'] ?? '-'}'
                              '${row['created_at'] != null ? ' · ${row['created_at']}' : ''}',
                              style: Theme.of(context).textTheme.titleSmall,
                            ),
                            Text(
                              '${row['item_name'] ?? '-'} · ${row['item_type_label'] ?? row['item_type'] ?? '-'}',
                            ),
                            Text(
                              'Txn: ${row['transaction_type_label'] ?? row['transaction_type'] ?? '-'}',
                            ),
                            Text(
                              'Ref: ${row['reference_number'] ?? '-'}',
                            ),
                            Text('Batch: ${row['batch_number'] ?? '-'}'),
                            Text(
                              'In: ${row['quantity_in'] ?? 0} · Out: ${row['quantity_out'] ?? 0}',
                            ),
                            Text(
                              'Balance: ${row['stock_after'] ?? '-'} ${row['unit'] ?? ''}',
                            ),
                            Text('Unit: ${row['unit'] ?? '-'}'),
                            Text(
                              'Created by: ${row['created_by_name'] ?? '-'}',
                            ),
                            if ((row['remarks'] ?? '').toString().isNotEmpty)
                              Text('Remark: ${row['remarks']}'),
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
