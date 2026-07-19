import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import '../models/order_line_item.dart';

class OrderLineItemCard extends StatefulWidget {
  const OrderLineItemCard({
    super.key,
    required this.item,
    required this.onChanged,
    required this.onRemove,
  });

  final OrderLineItem item;
  final VoidCallback onChanged;
  final VoidCallback onRemove;

  @override
  State<OrderLineItemCard> createState() => _OrderLineItemCardState();
}

class _OrderLineItemCardState extends State<OrderLineItemCard> {
  late final TextEditingController _caseQuantityController;
  late final TextEditingController _rateController;
  late final TextEditingController _discountController;

  OrderLineItem get item => widget.item;

  @override
  void initState() {
    super.initState();
    _caseQuantityController = TextEditingController(
      text: '${item.caseQuantity}',
    );
    _rateController = TextEditingController(text: _formatNumber(item.ratePerNo));
    _discountController = TextEditingController(
      text: _formatNumber(item.discountValue),
    );
  }

  @override
  void didUpdateWidget(covariant OrderLineItemCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.item.caseQuantity != item.caseQuantity) {
      _caseQuantityController.text = '${item.caseQuantity}';
    }
    if (oldWidget.item.ratePerNo != item.ratePerNo) {
      _rateController.text = _formatNumber(item.ratePerNo);
    }
    if (oldWidget.item.discountValue != item.discountValue) {
      _discountController.text = _formatNumber(item.discountValue);
    }
  }

  @override
  void dispose() {
    _caseQuantityController.dispose();
    _rateController.dispose();
    _discountController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 2,
    );
    final discountEnabled = item.isDiscountEnabled;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    item.productName,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
                IconButton(
                  onPressed: widget.onRemove,
                  icon: const Icon(Icons.delete_outline),
                  tooltip: 'Remove Item',
                ),
              ],
            ),
            Text(
              item.productCode,
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 12),
            _AmountRow(
              'Original Dealer Price',
              currency.format(item.originalDealerPrice),
            ),
            const SizedBox(height: 8),
            Text(
              'GST: ${item.gstPercent.toStringAsFixed(0)}%',
              style: Theme.of(context).textTheme.bodyLarge,
            ),
            const SizedBox(height: 12),
            LayoutBuilder(
              builder: (context, constraints) {
                final isWide = constraints.maxWidth >= 480;
                final caseField = _buildCaseQuantityField();
                final nosPerCaseField = _buildReadOnlyField(
                  label: 'Nos Per Case',
                  value: '${item.nosPerCase}',
                );
                final totalNosField = _buildReadOnlyField(
                  label: 'Total Nos',
                  value: '${item.totalQuantityNos}',
                );

                if (isWide) {
                  return Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(child: caseField),
                      const SizedBox(width: 12),
                      Expanded(child: nosPerCaseField),
                      const SizedBox(width: 12),
                      Expanded(child: totalNosField),
                    ],
                  );
                }

                return Column(
                  children: [
                    caseField,
                    const SizedBox(height: 12),
                    Row(
                      children: [
                        Expanded(child: nosPerCaseField),
                        const SizedBox(width: 12),
                        Expanded(child: totalNosField),
                      ],
                    ),
                  ],
                );
              },
            ),
            const SizedBox(height: 8),
            Text(
              item.displaySummary,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _rateController,
              decoration: const InputDecoration(
                labelText: 'Rate Per No',
                border: OutlineInputBorder(),
                prefixText: '₹ ',
              ),
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              inputFormatters: [
                FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')),
              ],
              onChanged: (value) {
                item.updateRatePerNo(double.tryParse(value) ?? 0);
                if (!item.isDiscountEnabled) {
                  _discountController.text = _formatNumber(item.discountValue);
                }
                widget.onChanged();
              },
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _discountController,
              enabled: discountEnabled,
              decoration: const InputDecoration(
                labelText: 'Discount Percentage',
                border: OutlineInputBorder(),
                suffixText: '%',
              ),
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              inputFormatters: [
                FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')),
              ],
              onChanged: discountEnabled
                  ? (value) {
                      item.discountValue = double.tryParse(value) ?? 0;
                      widget.onChanged();
                    }
                  : null,
            ),
            if (!discountEnabled) ...[
              const SizedBox(height: 8),
              Text(
                'Discount unavailable because rate was changed.',
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 12,
                ),
              ),
            ],
            const SizedBox(height: 12),
            _AmountRow('Base Amount', currency.format(item.baseAmount)),
            _AmountRow('Discount Amount', currency.format(item.discountAmount)),
            _AmountRow('Taxable Amount', currency.format(item.taxableAmount)),
            _AmountRow('GST Amount', currency.format(item.gstAmount)),
            _AmountRow(
              'Final Amount',
              currency.format(item.finalAmount),
              emphasized: true,
            ),
            if (item.validationErrors.isNotEmpty) ...[
              const SizedBox(height: 8),
              ...item.validationErrors.map(
                (error) => Text(
                  error,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildCaseQuantityField() => Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      IconButton(
        onPressed: item.caseQuantity <= 1
            ? null
            : () => _updateCaseQuantity(item.caseQuantity - 1),
        icon: const Icon(Icons.remove_circle_outline),
        tooltip: 'Decrease cases',
      ),
      Expanded(
        child: TextFormField(
          controller: _caseQuantityController,
          textAlign: TextAlign.center,
          decoration: const InputDecoration(
            labelText: 'Cases',
            border: OutlineInputBorder(),
          ),
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          onChanged: (value) {
            final parsed = int.tryParse(value) ?? 0;
            item.caseQuantity = parsed < 1 ? 1 : parsed;
            if (parsed < 1) {
              _caseQuantityController.text = '${item.caseQuantity}';
            }
            widget.onChanged();
          },
        ),
      ),
      IconButton(
        onPressed: () => _updateCaseQuantity(item.caseQuantity + 1),
        icon: const Icon(Icons.add_circle_outline),
        tooltip: 'Increase cases',
      ),
    ],
  );

  Widget _buildReadOnlyField({
    required String label,
    required String value,
  }) => InputDecorator(
    decoration: InputDecoration(
      labelText: label,
      border: const OutlineInputBorder(),
      filled: true,
      fillColor: Theme.of(context).colorScheme.surfaceContainerHighest,
    ),
    child: Text(value),
  );

  String _formatNumber(double value) =>
      value == value.roundToDouble() ? '${value.toInt()}' : '$value';

  void _updateCaseQuantity(int caseQuantity) {
    item.caseQuantity = caseQuantity < 1 ? 1 : caseQuantity;
    _caseQuantityController.text = '${item.caseQuantity}';
    widget.onChanged();
  }
}

class _AmountRow extends StatelessWidget {
  const _AmountRow(this.label, this.value, {this.emphasized = false});
  final String label;
  final String value;
  final bool emphasized;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 4),
    child: Row(
      children: [
        Expanded(child: Text(label)),
        Text(
          value,
          style: emphasized
              ? Theme.of(
                  context,
                ).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)
              : Theme.of(context).textTheme.bodyMedium,
        ),
      ],
    ),
  );
}
