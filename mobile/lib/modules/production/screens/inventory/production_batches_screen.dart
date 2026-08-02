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

class ProductionBatchesScreen extends StatefulWidget {
  const ProductionBatchesScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<ProductionBatchesScreen> createState() =>
      _ProductionBatchesScreenState();
}

class _ProductionBatchesScreenState extends State<ProductionBatchesScreen> {
  late Future<Map<String, dynamic>> _future;

  InventoryProductionApi get _api => InventoryProductionApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  @override
  void initState() {
    super.initState();
    _future = _api.listBatches();
  }

  Future<void> _reload() async {
    setState(() => _future = _api.listBatches());
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'Production Batches', auth: widget.auth),
      floatingActionButton: widget.auth.permissions.canCreateProduction
          ? FloatingActionButton.extended(
              onPressed: () => context.push('/production/entry'),
              icon: const Icon(Icons.add),
              label: const Text('New Production Entry'),
            )
          : null,
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
            final items = (snapshot.data?['data'] as List?)
                    ?.map((e) => Map<String, dynamic>.from(e as Map))
                    .toList() ??
                const [];
            if (items.isEmpty) {
              return const PgEmptyState(message: 'No production batches yet.');
            }
            return ListView.builder(
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              itemCount: items.length,
              itemBuilder: (context, index) {
                final batch = items[index];
                final id = int.tryParse('${batch['id']}') ?? 0;
                return PgCard(
                  margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                  onTap: id > 0
                      ? () => context.push('/production/batches/$id')
                      : null,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              '${batch['batch_number']}',
                              style: Theme.of(context).textTheme.titleSmall,
                            ),
                          ),
                          PgStatusBadge(
                            label:
                                '${batch['status_label'] ?? batch['status']}',
                          ),
                        ],
                      ),
                      Text(
                        '${batch['product_name'] ?? batch['output_item_name'] ?? '-'}',
                      ),
                      Text(
                        '${batch['production_date']} · Qty ${batch['production_quantity'] ?? '-'}',
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
