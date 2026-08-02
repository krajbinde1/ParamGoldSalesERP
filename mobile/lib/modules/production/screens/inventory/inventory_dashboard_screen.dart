import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/api/api_errors.dart';
import '../../../../core/design/app_colors.dart';
import '../../../../core/design/app_spacing.dart';
import '../../../../core/storage/session_store.dart';
import '../../../../core/widgets/design/pg_card.dart';
import '../../../../core/widgets/design/pg_empty_state.dart';
import '../../../../core/widgets/design/pg_quick_action.dart';
import '../../../../core/widgets/design/pg_status_badge.dart';
import '../../../../core/widgets/role_shell_widgets.dart';
import '../../../auth/providers/auth_controller.dart';
import '../../api/inventory_production_api.dart';

/// Production Supervisor Inventory landing — backend-driven summary cards +
/// allowed quick actions only (no Masters / Raw Material Inward).
class InventoryDashboardScreen extends StatefulWidget {
  const InventoryDashboardScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<InventoryDashboardScreen> createState() =>
      _InventoryDashboardScreenState();
}

class _InventoryDashboardScreenState extends State<InventoryDashboardScreen> {
  late Future<Map<String, dynamic>> _future;

  static final _inr = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 0,
  );

  InventoryProductionApi get _api => InventoryProductionApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  @override
  void initState() {
    super.initState();
    _future = _api.inventoryDashboard();
  }

  Future<void> _reload() async {
    setState(() => _future = _api.inventoryDashboard());
    await _future;
  }

  Map<String, dynamic> _map(dynamic value) =>
      Map<String, dynamic>.from(value as Map? ?? const {});

  String _date(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-'
      '${d.month.toString().padLeft(2, '0')}-'
      '${d.day.toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'Inventory', auth: widget.auth),
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
            final cards = _map(data['cards']);
            final batches = (data['recent_batches'] as List?)
                    ?.map((e) => Map<String, dynamic>.from(e as Map))
                    .toList() ??
                const [];
            final p = widget.auth.permissions;
            final now = DateTime.now();
            final today = DateTime(now.year, now.month, now.day);
            final monthStart = DateTime(now.year, now.month, 1);

            final rm = _map(cards['raw_material']);
            final sf = _map(cards['semi_finished']);
            final fg = _map(cards['finished_product']);
            final todayProd = _map(cards['today_production']);
            final monthProd = _map(cards['month_production']);
            final lowStock = _map(cards['low_stock']);

            final todayQtySafe = todayProd['produced_qty_unit_safe'] == true;
            final todayQty = todayProd['produced_qty'];

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                Text(
                  'Overview',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                ),
                const SizedBox(height: AppSpacing.sm),
                LayoutBuilder(
                  builder: (context, constraints) {
                    final width = (constraints.maxWidth - 12) / 2;
                    return Wrap(
                      spacing: 12,
                      runSpacing: 12,
                      children: [
                        _SummaryCard(
                          width: width,
                          title: 'Raw Material Stock',
                          primary:
                              '${rm['item_count'] ?? cards['raw_material_item_count'] ?? 0} items',
                          secondary: _formatValue(
                            rm['stock_value'] ?? cards['raw_material_value'],
                          ),
                          icon: const Icon(Icons.inventory_2_outlined),
                          onTap: p.canViewStockReport
                              ? () => context.push(
                                    '/production/stock-report?type=raw_material',
                                  )
                              : null,
                        ),
                        _SummaryCard(
                          width: width,
                          title: 'Semi-Finished',
                          primary:
                              '${sf['item_count'] ?? 0} items',
                          secondary: _formatValue(
                            sf['stock_value'] ?? cards['semi_finished_value'],
                          ),
                          icon: const Icon(Icons.hub_outlined),
                          onTap: p.canViewStockReport
                              ? () => context.push(
                                    '/production/stock-report?type=semi_finished',
                                  )
                              : null,
                        ),
                        _SummaryCard(
                          width: width,
                          title: 'Finished Product',
                          primary:
                              '${fg['item_count'] ?? 0} items',
                          secondary: _formatValue(
                            fg['stock_value'] ?? cards['finished_goods_value'],
                          ),
                          icon: const Icon(Icons.verified_outlined),
                          onTap: p.canViewStockReport
                              ? () => context.push(
                                    '/production/stock-report?type=finished_product',
                                  )
                              : null,
                        ),
                        _SummaryCard(
                          width: width,
                          title: "Today's Production",
                          primary:
                              '${todayProd['entry_count'] ?? cards['today_production_batches'] ?? 0} entries',
                          secondary: todayQtySafe && todayQty != null
                              ? 'Qty $todayQty'
                              : null,
                          icon: const Icon(Icons.today_outlined),
                          onTap: p.canViewProductionHistory || p.canViewInventory
                              ? () => context.push(
                                    '/production/history?from=${_date(today)}&to=${_date(today)}',
                                  )
                              : null,
                        ),
                        _SummaryCard(
                          width: width,
                          title: 'This Month Production',
                          primary:
                              '${monthProd['entry_count'] ?? cards['month_production_batches'] ?? 0} entries',
                          icon: const Icon(Icons.calendar_month_outlined),
                          onTap: p.canViewProductionHistory || p.canViewInventory
                              ? () => context.push(
                                    '/production/history?from=${_date(monthStart)}&to=${_date(today)}',
                                  )
                              : null,
                        ),
                        _SummaryCard(
                          width: width,
                          title: 'Low Stock',
                          primary:
                              '${lowStock['item_count'] ?? cards['low_stock_items'] ?? 0} items',
                          icon: const Icon(Icons.warning_amber_outlined),
                          accent: AppColors.warning,
                          onTap: p.canViewStockReport
                              ? () => context.push(
                                    '/production/stock-report?status=low_stock',
                                  )
                              : null,
                        ),
                      ],
                    );
                  },
                ),
                const SizedBox(height: AppSpacing.lg),
                const PgSectionHeader(title: 'Quick Actions'),
                Wrap(
                  spacing: 16,
                  runSpacing: 12,
                  children: [
                    if (p.canViewStockReport)
                      PgQuickAction(
                        icon: const Icon(Icons.document_scanner_outlined),
                        label: 'Stock Report',
                        color: AppColors.primary,
                        onTap: () => context.push('/production/stock-report'),
                      ),
                    if (p.canCreateProduction)
                      PgQuickAction(
                        icon: const Icon(Icons.add_circle_outline),
                        label: 'New Production',
                        color: AppColors.success,
                        onTap: () => context.push('/production/entry'),
                      ),
                    if (p.canViewProductionHistory || p.canViewInventory)
                      PgQuickAction(
                        icon: const Icon(Icons.history),
                        label: 'History',
                        color: AppColors.info,
                        onTap: () => context.push('/production/history'),
                      ),
                    if (p.canViewStockReport || p.canViewInventory)
                      PgQuickAction(
                        icon: const Icon(Icons.receipt_long_outlined),
                        label: 'Stock Ledger',
                        color: AppColors.primary,
                        onTap: () => context.push('/production/stock-ledger'),
                      ),
                    if (p.canAdjustStock)
                      PgQuickAction(
                        icon: const Icon(Icons.tune_outlined),
                        label: 'Stock Adjustment',
                        color: AppColors.warning,
                        onTap: () => context.push(
                          '/production/stock-ledger?txn=stock_adjustment',
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: AppSpacing.lg),
                const PgSectionHeader(title: 'Recent Production Batches'),
                if (batches.isEmpty)
                  const PgEmptyState(message: 'No recent production batches.')
                else
                  ...batches.map(
                    (batch) => PgCard(
                      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                      onTap: () => context.push(
                        '/production/batches/${batch['id']}',
                      ),
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
                            'Qty ${batch['production_quantity'] ?? batch['actual_output_quantity'] ?? '-'}',
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

  String? _formatValue(dynamic value) {
    if (value == null) return null;
    final n = double.tryParse('$value');
    if (n == null) return null;
    return _inr.format(n);
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({
    required this.width,
    required this.title,
    required this.primary,
    required this.icon,
    this.secondary,
    this.onTap,
    this.accent,
  });

  final double width;
  final String title;
  final String primary;
  final String? secondary;
  final Widget icon;
  final VoidCallback? onTap;
  final Color? accent;

  @override
  Widget build(BuildContext context) {
    final color = accent ?? AppColors.primary;
    return SizedBox(
      width: width,
      child: PgCard(
        onTap: onTap,
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(
                    color: color.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(AppSpacing.radiusSm),
                  ),
                  child: IconTheme(
                    data: IconThemeData(color: color, size: 20),
                    child: Center(child: icon),
                  ),
                ),
                const Spacer(),
                if (onTap != null)
                  const Icon(
                    Icons.chevron_right,
                    size: 18,
                    color: AppColors.textSecondary,
                  ),
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            Text(
              title,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.labelMedium?.copyWith(
                    color: AppColors.textSecondary,
                    fontWeight: FontWeight.w600,
                  ),
            ),
            const SizedBox(height: 4),
            Text(
              primary,
              style: Theme.of(context).textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
            ),
            if (secondary != null) ...[
              const SizedBox(height: 2),
              Text(
                secondary!,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: AppColors.textSecondary,
                    ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
