import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/utils/bill_document.dart';
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
import '../widgets/order_info_card.dart';
import '../widgets/order_invoice_products_table.dart';
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
            final billUrl = detail.billUrl?.trim() ?? '';

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                PgCard(
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          detail.displayOrderNo,
                          style: Theme.of(context).textTheme.titleLarge,
                        ),
                      ),
                      PgStatusBadge(
                        label: OrderStatusRules.badgeLabel(
                          detail.status,
                          rejectedByRole: detail.rejectedByRole,
                          statusLabel: detail.statusLabel,
                        ),
                        tone: _statusTone(detail.status),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                OrderInfoCard(
                  orderDate:
                      DateFormat('d MMM yyyy').format(detail.orderDate),
                  createdBy: detail.salesEmployeeName,
                  dealerName: detail.dealerName,
                  dealerVillage: (detail.dealer?.village ?? '').trim().isEmpty
                      ? '—'
                      : detail.dealer!.village!.trim(),
                  remarks: detail.remarks,
                ),
                if ((detail.approvalSummary ?? '').trim().isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.md),
                  PgCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Approval Information',
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        const SizedBox(height: AppSpacing.sm),
                        Text(detail.approvalSummary!.trim()),
                      ],
                    ),
                  ),
                ],
                if (billUrl.isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.md),
                  FilledButton.icon(
                    onPressed: () => openBillDocument(
                      context,
                      url: billUrl,
                      title: 'Bill PDF',
                    ),
                    icon: const Icon(Icons.picture_as_pdf_outlined),
                    label: const Text('View Bill / Open Bill PDF'),
                  ),
                ],
                if ((detail.rejectionRemark ?? '').trim().isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.md),
                  PgCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Rejection Details',
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        const SizedBox(height: AppSpacing.sm),
                        PgInvoiceRow(
                          label: 'Rejected By',
                          value: detail.rejectedByName ??
                              detail.rejectedByRole ??
                              '—',
                        ),
                        if ((detail.rejectedByRole ?? '').isNotEmpty)
                          PgInvoiceRow(
                            label: 'Role',
                            value: detail.rejectedByRole!,
                          ),
                        if ((detail.rejectedAt ?? '').isNotEmpty)
                          PgInvoiceRow(
                            label: 'Rejection Date',
                            value: detail.rejectedAt!,
                          ),
                        PgInvoiceRow(
                          label: 'Reason',
                          value: detail.rejectionRemark!.trim(),
                        ),
                      ],
                    ),
                  ),
                ],
                const SizedBox(height: AppSpacing.md),
                OrderInvoiceProductsCard.sharedReview(
                  title: 'Order Items',
                  lines: detail.items
                      .map(OrderInvoiceLine.fromDetailItem)
                      .toList(growable: false),
                  summary: OrderInvoiceSummaryBlock(
                    showTitle: true,
                    subtotal: detail.subtotal,
                    discount: detail.discountAmount,
                    gst: detail.gstAmount,
                    grandTotal: detail.grandTotal,
                    transport: detail.transportAmount,
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
