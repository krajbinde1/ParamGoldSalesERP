import 'package:flutter/material.dart';
import '../../../core/api/api_client.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/order_api.dart';
import '../models/order_draft.dart';
import '../widgets/order_invoice_products_table.dart';

class ReviewOrderScreen extends StatefulWidget {
  const ReviewOrderScreen({super.key, required this.draft, required this.auth});
  final OrderDraft draft;
  final AuthController auth;

  @override
  State<ReviewOrderScreen> createState() => _ReviewOrderScreenState();
}

class _ReviewOrderScreenState extends State<ReviewOrderScreen> {
  bool _submitting = false;
  String? _error;

  Future<void> _submit() async {
    if (_submitting || !widget.draft.canSubmit) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      final api = OrderApi(
        ApiClient(
          SessionStore(),
          onUnauthorized: widget.auth.sessionExpired,
        ).dio,
      );

      final result = widget.draft.isEditing
          ? await api.update(widget.draft.orderId!, widget.draft.toSubmitJson())
          : await api.submit(widget.draft.toSubmitJson());

      if (!mounted) return;

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('${result.message} ${result.orderNo}')),
      );

      if (widget.draft.isEditing) {
        safeGo(context, '/orders/${widget.draft.orderId}');
      } else {
        safeGo(context, '/orders');
      }
    } catch (error) {
      if (!mounted) return;
      setState(() => _error = '$error');
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final summary = widget.draft.summary;
    final draft = widget.draft;

    return PgPageScaffold(
      title: draft.isEditing ? 'Review Changes' : 'Review Order',
      showBack: true,
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenPadding),
        children: [
          PgCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Dealer', style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: AppSpacing.sm),
                PgInvoiceRow(label: 'Dealer Name', value: draft.dealer.name),
                if (draft.dealer.ownerName != null &&
                    draft.dealer.ownerName!.isNotEmpty)
                  PgInvoiceRow(
                    label: 'Owner Name',
                    value: draft.dealer.ownerName!,
                  ),
                if (draft.dealer.village != null &&
                    draft.dealer.village!.isNotEmpty)
                  PgInvoiceRow(label: 'Village', value: draft.dealer.village!),
                if (draft.dealer.mobile != null &&
                    draft.dealer.mobile!.isNotEmpty)
                  PgInvoiceRow(label: 'Mobile', value: draft.dealer.mobile!),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          OrderInvoiceProductsCard(
            lines: draft.items
                .map(OrderInvoiceLine.fromLineItem)
                .toList(growable: false),
            summary: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                PgInvoiceRow(
                  label: 'Total Products',
                  value: '${summary.totalProducts}',
                ),
                PgInvoiceRow(
                  label: 'Total Cases',
                  value: '${summary.totalCases}',
                ),
                PgInvoiceRow(
                  label: 'Total Quantity (Nos)',
                  value: '${summary.totalQuantityNos}',
                ),
                const SizedBox(height: AppSpacing.sm),
                OrderInvoiceSummaryBlock(
                  showTitle: false,
                  subtotal: summary.subtotal,
                  discount: summary.totalDiscount,
                  gst: summary.totalGst,
                  grandTotal: summary.grandTotal,
                ),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          PgCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Remarks', style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: AppSpacing.sm),
                Text(
                  draft.remarks.trim().isEmpty ? '—' : draft.remarks.trim(),
                ),
              ],
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
          ],
          const SizedBox(height: AppSpacing.lg),
          Row(
            children: [
              Expanded(
                child: OutlinedButton(
                  onPressed: _submitting ? null : () => safePop(context),
                  child: const Text('Edit Order'),
                ),
              ),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: FilledButton(
                  onPressed: _submitting || !draft.canSubmit ? null : _submit,
                  child: _submitting
                      ? const SizedBox.square(
                          dimension: 20,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : Text(
                          draft.isEditing
                              ? 'Confirm & Save'
                              : 'Confirm & Submit',
                        ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

}
