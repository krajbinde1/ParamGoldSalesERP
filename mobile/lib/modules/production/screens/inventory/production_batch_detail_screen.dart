import 'package:flutter/material.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/api/api_errors.dart';
import '../../../../core/design/app_spacing.dart';
import '../../../../core/storage/session_store.dart';
import '../../../../core/widgets/design/pg_card.dart';
import '../../../../core/widgets/design/pg_empty_state.dart';
import '../../../../core/widgets/design/pg_quick_action.dart';
import '../../../../core/widgets/design/pg_status_badge.dart';
import '../../../../core/widgets/role_shell_widgets.dart';
import '../../../auth/providers/auth_controller.dart';
import '../../api/inventory_production_api.dart';

class ProductionBatchDetailScreen extends StatefulWidget {
  const ProductionBatchDetailScreen({
    super.key,
    required this.auth,
    required this.batchId,
  });

  final AuthController auth;
  final int batchId;

  @override
  State<ProductionBatchDetailScreen> createState() =>
      _ProductionBatchDetailScreenState();
}

class _ProductionBatchDetailScreenState
    extends State<ProductionBatchDetailScreen> {
  late Future<Map<String, dynamic>> _future;

  InventoryProductionApi get _api => InventoryProductionApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  @override
  void initState() {
    super.initState();
    _future = _api.batchDetail(widget.batchId);
  }

  Future<void> _reload() async {
    setState(() => _future = _api.batchDetail(widget.batchId));
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    final canCosts = widget.auth.permissions.canViewProductionCosts;

    return Scaffold(
      appBar: RoleAppBar(title: 'Batch Detail', auth: widget.auth),
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
              return PgErrorState(
                message: errorMessage(snapshot.error),
                onRetry: _reload,
              );
            }
            final batch = snapshot.data!;
            final consumptions = (batch['consumptions'] as List?)
                    ?.map((e) => Map<String, dynamic>.from(e as Map))
                    .toList() ??
                const [];
            final status = batch['status']?.toString() ?? '';

            return ListView(
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                PgCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              '${batch['batch_number']}',
                              style: Theme.of(context).textTheme.titleMedium,
                            ),
                          ),
                          PgStatusBadge(
                            label: '${batch['status_label'] ?? status}',
                          ),
                        ],
                      ),
                      Text(
                        '${batch['product_name'] ?? batch['output_item_name'] ?? '-'}',
                      ),
                      Text(
                        'Type: ${batch['output_type'] ?? '-'}',
                      ),
                      Text('Date: ${batch['production_date']}'),
                      Text(
                        'Planned: ${batch['planned_quantity'] ?? batch['production_quantity'] ?? '-'}',
                      ),
                      Text(
                        'Actual produced: ${batch['actual_output_quantity'] ?? batch['production_quantity'] ?? '-'}',
                      ),
                      Text(
                        'Wastage: ${batch['wastage_quantity'] ?? 0}',
                      ),
                      Text('Supervisor: ${batch['supervisor_name'] ?? '-'}'),
                      if (batch['stock_posted'] == true)
                        const Text('Output stock posted: Yes'),
                      if (batch['finished_product_ledger_id'] != null)
                        Text(
                          'FG ledger ref: #${batch['finished_product_ledger_id']}',
                        ),
                      if (batch['semi_finished_ledger_id'] != null)
                        Text(
                          'SF ledger ref: #${batch['semi_finished_ledger_id']}',
                        ),
                      if (canCosts && batch['labour_cost'] != null)
                        Text('Labour: ₹${batch['labour_cost']}'),
                      if (canCosts && batch['transport_cost'] != null)
                        Text('Transport: ₹${batch['transport_cost']}'),
                      if (canCosts &&
                          batch['other_manufacturing_cost'] != null)
                        Text(
                          'Other: ₹${batch['other_manufacturing_cost']}',
                        ),
                      if (canCosts && batch['total_batch_cost'] != null)
                        Text(
                          'Total cost ₹${batch['total_batch_cost']} (₹${batch['cost_per_unit']}/unit)',
                        ),
                      if (batch['notes'] != null)
                        Text('Remarks: ${batch['notes']}'),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                const PgSectionHeader(title: 'Material Consumption'),
                if (consumptions.isEmpty)
                  const PgEmptyState(message: 'No consumption lines.')
                else
                  ...consumptions.map(
                    (c) => PgCard(
                      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            '${c['material_name']}',
                            style: Theme.of(context).textTheme.titleSmall,
                          ),
                          Text('Type: ${c['item_type']}'),
                          Text(
                            'Consumed ${c['consumed_quantity']} ${c['unit'] ?? ''}',
                          ),
                          Text(
                            'Stock before/after: ${c['stock_before']} → ${c['stock_after']}',
                          ),
                          if (canCosts && c['consumption_value'] != null)
                            Text('Value: ₹${c['consumption_value']}'),
                          if (c['id'] != null)
                            Text(
                              'Ledger ref: consumption #${c['id']}',
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                        ],
                      ),
                    ),
                  ),
              ],
            );
          },
        ),
      ),
    );
  }
}
