import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';

import '../../../core/design/app_colors.dart';
import '../models/order_line_item.dart';

Future<void> showOrderLineItemEditor({
  required BuildContext context,
  required OrderLineItem item,
  required VoidCallback onChanged,
  VoidCallback? onRemove,
  int? serialNumber,
}) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (sheetContext) {
      return Padding(
        padding: EdgeInsets.only(
          bottom: MediaQuery.viewInsetsOf(sheetContext).bottom,
        ),
        child: SafeArea(
          child: SingleChildScrollView(
            child: OrderLineItemCard(
              item: item,
              serialNumber: serialNumber,
              embedded: true,
              onChanged: onChanged,
              onRemove: () {
                onRemove?.call();
                Navigator.of(sheetContext).pop();
              },
            ),
          ),
        ),
      );
    },
  );
}

class OrderLineItemCard extends StatefulWidget {
  const OrderLineItemCard({
    super.key,
    required this.item,
    required this.onChanged,
    required this.onRemove,
    this.serialNumber,
    this.embedded = false,
  });

  final OrderLineItem item;
  final VoidCallback onChanged;
  final VoidCallback onRemove;
  /// 1-based display index (#01, #02, …). Updates with list order.
  final int? serialNumber;
  final bool embedded;

  @override
  State<OrderLineItemCard> createState() => _OrderLineItemCardState();
}

class _OrderLineItemCardState extends State<OrderLineItemCard> {
  late final TextEditingController _caseQuantityController;
  late final TextEditingController _discountController;
  late final FocusNode _caseQuantityFocus;

  OrderLineItem get item => widget.item;

  String get _serialLabel {
    final n = widget.serialNumber;
    if (n == null || n < 1) return '';
    return '#${n.toString().padLeft(2, '0')}';
  }

  @override
  void initState() {
    super.initState();
    _caseQuantityController = TextEditingController(
      text: '${item.caseQuantity}',
    );
    _discountController = TextEditingController(
      text: _formatNumber(item.discountValue),
    );
    _caseQuantityFocus = FocusNode()..addListener(_onCaseQuantityFocusChange);
  }

  @override
  void didUpdateWidget(covariant OrderLineItemCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.item.caseQuantity != item.caseQuantity &&
        !_caseQuantityFocus.hasFocus) {
      _caseQuantityController.text = '${item.caseQuantity}';
    }
    if (oldWidget.item.discountValue != item.discountValue) {
      _discountController.text = _formatNumber(item.discountValue);
    }
  }

  @override
  void dispose() {
    _caseQuantityFocus
      ..removeListener(_onCaseQuantityFocusChange)
      ..dispose();
    _caseQuantityController.dispose();
    _discountController.dispose();
    super.dispose();
  }

  void _onCaseQuantityFocusChange() {
    if (_caseQuantityFocus.hasFocus) {
      // Defer so Flutter's tap/cursor handling does not clear the selection.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted || !_caseQuantityFocus.hasFocus) return;
        _selectAllCaseQuantity();
      });
      return;
    }
    // Clamp empty / invalid values when the field loses focus.
    final parsed = int.tryParse(_caseQuantityController.text.trim()) ?? 0;
    _setCaseQuantity(parsed < 1 ? 1 : parsed);
  }

  void _selectAllCaseQuantity() {
    final text = _caseQuantityController.text;
    _caseQuantityController.selection = TextSelection(
      baseOffset: 0,
      extentOffset: text.length,
    );
  }

  void _setCaseQuantity(int value) {
    final next = value < 1 ? 1 : value;
    item.caseQuantity = next;
    final text = '$next';
    if (_caseQuantityController.text != text) {
      _caseQuantityController.value = TextEditingValue(
        text: text,
        selection: TextSelection.collapsed(offset: text.length),
      );
    }
    widget.onChanged();
    setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 2,
    );
    final theme = Theme.of(context);
    final serial = _serialLabel;

    return Card(
      margin: widget.embedded
          ? const EdgeInsets.fromLTRB(12, 0, 12, 14)
          : const EdgeInsets.only(bottom: 14),
      elevation: 1.5,
      shadowColor: AppColors.shadow,
      color: AppColors.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: AppColors.border, width: 1.2),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 8, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (serial.isNotEmpty) ...[
                  _SerialBadge(label: serial),
                  const SizedBox(width: 10),
                ],
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          item.productName,
                          style: theme.textTheme.titleSmall?.copyWith(
                            fontWeight: FontWeight.w700,
                            color: AppColors.textPrimary,
                            height: 1.25,
                          ),
                        ),
                        if (item.productCode.isNotEmpty) ...[
                          const SizedBox(height: 2),
                          Text(
                            item.productCode,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: AppColors.textMuted,
                              fontSize: 12,
                              height: 1.2,
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
                IconButton(
                  onPressed: widget.onRemove,
                  icon: const Icon(Icons.delete_outline),
                  color: AppColors.error,
                  tooltip: 'Remove Item',
                  visualDensity: VisualDensity.compact,
                  constraints: const BoxConstraints(
                    minWidth: 36,
                    minHeight: 36,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: InputDecorator(
                    decoration: const InputDecoration(
                      labelText: 'Cases',
                      border: OutlineInputBorder(),
                      isDense: true,
                      contentPadding: EdgeInsets.symmetric(
                        horizontal: 4,
                        vertical: 6,
                      ),
                    ),
                    child: Row(
                      children: [
                        IconButton(
                          onPressed: item.caseQuantity <= 1
                              ? null
                              : () => _setCaseQuantity(item.caseQuantity - 1),
                          icon: const Icon(Icons.remove),
                          tooltip: 'Decrease cases',
                          visualDensity: VisualDensity.compact,
                          constraints: const BoxConstraints(
                            minWidth: 36,
                            minHeight: 36,
                          ),
                        ),
                        Expanded(
                          child: TextField(
                            controller: _caseQuantityController,
                            focusNode: _caseQuantityFocus,
                            textAlign: TextAlign.center,
                            keyboardType: TextInputType.number,
                            inputFormatters: [
                              FilteringTextInputFormatter.digitsOnly,
                            ],
                            decoration: const InputDecoration(
                              isDense: true,
                              border: InputBorder.none,
                              contentPadding: EdgeInsets.zero,
                            ),
                            style: theme.textTheme.bodyLarge?.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                            onTap: () {
                              WidgetsBinding.instance.addPostFrameCallback((_) {
                                if (!mounted || !_caseQuantityFocus.hasFocus) {
                                  return;
                                }
                                _selectAllCaseQuantity();
                              });
                            },
                            onChanged: (value) {
                              final parsed = int.tryParse(value.trim());
                              if (parsed == null || parsed < 1) return;
                              item.caseQuantity = parsed;
                              widget.onChanged();
                              setState(() {});
                            },
                          ),
                        ),
                        IconButton(
                          onPressed: () =>
                              _setCaseQuantity(item.caseQuantity + 1),
                          icon: const Icon(Icons.add),
                          tooltip: 'Increase cases',
                          visualDensity: VisualDensity.compact,
                          constraints: const BoxConstraints(
                            minWidth: 36,
                            minHeight: 36,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: InputDecorator(
                    decoration: const InputDecoration(
                      labelText: 'Qty',
                      border: OutlineInputBorder(),
                      isDense: true,
                      filled: true,
                    ),
                    child: Text(
                      item.nosPerCase < 1 ? '—' : '${item.totalQuantityNos}',
                      style: theme.textTheme.bodyLarge?.copyWith(
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ),
              ],
            ),
            if (item.nosPerCase >= 1) ...[
              const SizedBox(height: 4),
              Text(
                'Qty = Cases × ${item.nosPerCase} (Qty Per Case)',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: AppColors.textMuted,
                  fontSize: 11,
                ),
              ),
            ],
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: InputDecorator(
                    decoration: const InputDecoration(
                      labelText: 'Rate',
                      border: OutlineInputBorder(),
                      isDense: true,
                      filled: true,
                      prefixText: '₹ ',
                    ),
                    child: Text(
                      currency
                          .format(item.ratePerNo)
                          .replaceFirst('₹', '')
                          .trim(),
                      style: theme.textTheme.bodyLarge?.copyWith(
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: TextFormField(
                    controller: _discountController,
                    enabled: item.isDiscountEnabled,
                    decoration: const InputDecoration(
                      labelText: 'Disc %',
                      border: OutlineInputBorder(),
                      isDense: true,
                      suffixText: '%',
                    ),
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    inputFormatters: [
                      FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')),
                    ],
                    onChanged: item.isDiscountEnabled
                        ? (value) {
                            item.discountValue = double.tryParse(value) ?? 0;
                            widget.onChanged();
                          }
                        : null,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              decoration: BoxDecoration(
                color: const Color(0xFFF0FDFA),
                borderRadius: BorderRadius.circular(8),
                border: Border.all(
                  color: AppColors.primary.withValues(alpha: 0.18),
                ),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      'Amount Without GST',
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: AppColors.textSecondary,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ),
                  Text(
                    currency.format(item.amountWithoutGst),
                    style: theme.textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: AppColors.textPrimary,
                      letterSpacing: -0.2,
                    ),
                  ),
                ],
              ),
            ),
            if (item.validationErrors.isNotEmpty) ...[
              const SizedBox(height: 8),
              ...item.validationErrors.map(
                (error) => Text(
                  error,
                  style: TextStyle(
                    color: theme.colorScheme.error,
                    fontSize: 12,
                  ),
                ),
              ),
            ],
            if (widget.embedded) ...[
              const SizedBox(height: 12),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: () => Navigator.of(context).maybePop(),
                  child: const Text('Done'),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  String _formatNumber(double value) =>
      value == value.roundToDouble() ? '${value.toInt()}' : '$value';
}

class _SerialBadge extends StatelessWidget {
  const _SerialBadge({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 36,
      height: 36,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: AppColors.primary.withValues(alpha: 0.12),
        shape: BoxShape.circle,
        border: Border.all(
          color: AppColors.primary.withValues(alpha: 0.28),
        ),
      ),
      child: Text(
        label,
        style: const TextStyle(
          color: AppColors.primary,
          fontWeight: FontWeight.w800,
          fontSize: 11,
          height: 1,
        ),
      ),
    );
  }
}
