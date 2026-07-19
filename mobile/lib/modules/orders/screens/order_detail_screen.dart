import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/order_api.dart';
import '../models/order.dart';
import '../models/order_draft.dart';
import '../models/order_detail.dart';
import '../widgets/order_widgets.dart';

class OrderDetailScreen extends StatefulWidget {
  const OrderDetailScreen({
    super.key,
    required this.orderId,
    required this.auth,
  });
  final int orderId;
  final AuthController auth;

  @override
  State<OrderDetailScreen> createState() => _OrderDetailScreenState();
}

class _OrderDetailScreenState extends State<OrderDetailScreen> {
  late Future<OrderDetail> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<OrderDetail> _load() => OrderApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  ).getOrder(widget.orderId);

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _editOrder(OrderDetail detail) async {
    final dealer = detail.dealer;
    if (dealer == null) return;

    final draft = OrderDraft(
      orderId: detail.id,
      dealer: dealer,
      items: detail.toLineItems(),
      remarks: detail.remarks,
    );

    await context.push('/orders/${detail.id}/edit', extra: draft);
    if (!mounted) return;
    await _reload();
  }

  PgStatusTone _statusTone(String status) {
    final tone = OrderStatusRules.badgeTone(status);
    return switch (tone) {
      OrderBadgeTone.pending => PgStatusTone.pending,
      OrderBadgeTone.approved => PgStatusTone.approved,
      OrderBadgeTone.dispatched => PgStatusTone.dispatched,
      OrderBadgeTone.rejected => PgStatusTone.rejected,
    };
  }

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 2,
    );

    return PgPageScaffold(
      title: 'Order Details',
      showBack: true,
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<OrderDetail>(
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
                    message: 'Unable to load order.',
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final detail = snapshot.data!;
            final timeline = detail.timeline;

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                PgDetailHeader(
                  title: detail.orderNo,
                  subtitle: detail.dealerName,
                  badgeLabel: OrderStatusRules.badgeLabel(detail.status),
                  badgeTone: _statusTone(detail.status),
                ),
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Order Info',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      PgInvoiceRow(
                        label: 'Order Date',
                        value: DateFormat('d MMM yyyy').format(detail.orderDate),
                      ),
                      PgInvoiceRow(
                        label: 'Sales Employee',
                        value: detail.salesEmployeeName,
                      ),
                      PgInvoiceRow(
                        label: 'Remarks',
                        value: detail.remarks.trim().isEmpty
                            ? '—'
                            : detail.remarks.trim(),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Order Items',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      ...detail.items.map(
                        (item) => Padding(
                          padding: const EdgeInsets.only(bottom: AppSpacing.md),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                item.productName,
                                style: Theme.of(context).textTheme.titleSmall,
                              ),
                              if (item.productCode.isNotEmpty)
                                Text(
                                  item.productCode,
                                  style: Theme.of(context).textTheme.bodySmall
                                      ?.copyWith(color: AppColors.textSecondary),
                                ),
                              const SizedBox(height: AppSpacing.sm),
                              PgInvoiceRow(
                                label: 'Quantity',
                                value: item.quantitySummary,
                              ),
                              PgInvoiceRow(
                                label: 'Cases',
                                value: '${item.caseQuantity}',
                              ),
                              PgInvoiceRow(
                                label: 'Nos Per Case',
                                value: '${item.nosPerCase}',
                              ),
                              PgInvoiceRow(
                                label: 'Total Nos',
                                value: '${item.totalQuantityNos}',
                              ),
                              if (item.originalDealerPrice != null)
                                PgInvoiceRow(
                                  label: 'Original Dealer Price',
                                  value: currency.format(item.originalDealerPrice),
                                ),
                              PgInvoiceRow(
                                label: 'Rate Per No',
                                value: currency.format(item.ratePerNo),
                              ),
                              PgInvoiceRow(
                                label: 'Discount %',
                                value:
                                    '${item.discountPercentage.toStringAsFixed(item.discountPercentage % 1 == 0 ? 0 : 2)}%',
                              ),
                              PgInvoiceRow(
                                label: 'GST %',
                                value: '${item.gstPercentage.toStringAsFixed(0)}%',
                              ),
                              PgInvoiceRow(
                                label: 'Item Amount',
                                value: currency.format(item.lineTotal),
                                emphasize: true,
                              ),
                              if (item != detail.items.last)
                                const Divider(height: AppSpacing.lg),
                            ],
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Order Summary',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      if (detail.totalCases != null)
                        PgInvoiceRow(
                          label: 'Total Cases',
                          value: '${detail.totalCases}',
                        ),
                      if (detail.totalQuantityNos != null)
                        PgInvoiceRow(
                          label: 'Total Quantity (Nos)',
                          value: '${detail.totalQuantityNos}',
                        ),
                      PgInvoiceRow(
                        label: 'Subtotal',
                        value: currency.format(detail.subtotal),
                      ),
                      PgInvoiceRow(
                        label: 'Total Discount',
                        value: currency.format(detail.discountAmount),
                      ),
                      PgInvoiceRow(
                        label: 'Total GST',
                        value: currency.format(detail.gstAmount),
                      ),
                      const Divider(height: AppSpacing.lg),
                      PgInvoiceRow(
                        label: 'Grand Total',
                        value: currency.format(detail.grandTotal),
                        isTotal: true,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Status Timeline',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      ...timeline.asMap().entries.map(
                        (entry) => OrderTimelineRow(
                          step: entry.value,
                          isLast: entry.key == timeline.length - 1,
                        ),
                      ),
                    ],
                  ),
                ),
                if (detail.canEdit) ...[
                  const SizedBox(height: AppSpacing.lg),
                  SizedBox(
                    width: double.infinity,
                    height: 52,
                    child: FilledButton.icon(
                      onPressed: () => _editOrder(detail),
                      icon: const Icon(Icons.edit_outlined),
                      label: const Text('Edit Order'),
                    ),
                  ),
                ],
              ],
            );
          },
        ),
      ),
    );
  }
}
