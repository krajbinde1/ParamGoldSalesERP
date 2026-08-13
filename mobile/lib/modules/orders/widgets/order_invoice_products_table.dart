import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../models/order_detail.dart';
import '../models/order_line_item.dart';

/// Normalized display row for the shared invoice-style products table.
class OrderInvoiceLine {
  const OrderInvoiceLine({
    required this.productName,
    required this.productCode,
    required this.caseQuantity,
    required this.nosPerCase,
    required this.totalQuantityNos,
    required this.rate,
    required this.discountPercent,
    required this.gstPercent,
    required this.amount,
    this.originalDealerPrice,
    this.discountAmount,
    this.gstAmount,
    this.taxableAmount,
    this.baseAmount,
    this.unit,
    this.quantitySummary,
  });

  final String productName;
  final String productCode;
  final int caseQuantity;
  final int nosPerCase;
  final int totalQuantityNos;
  final double rate;
  final double? originalDealerPrice;
  final double discountPercent;
  final double? discountAmount;
  final double gstPercent;
  final double? gstAmount;
  final double? taxableAmount;
  final double? baseAmount;
  final double amount;
  final String? unit;
  final String? quantitySummary;

  String get compactQtySummary {
    if ((quantitySummary ?? '').trim().isNotEmpty) {
      return quantitySummary!.trim();
    }
    final caseLabel = caseQuantity == 1 ? 'Case' : 'Cases';
    return '$caseQuantity $caseLabel × $nosPerCase = $totalQuantityNos Nos';
  }

  String get shortQtyHint =>
      '$caseQuantity×$nosPerCase';

  bool get hasDistinctOriginalRate {
    if (originalDealerPrice == null) return false;
    return (originalDealerPrice! - rate).abs() >= 0.001;
  }

  factory OrderInvoiceLine.fromLineItem(OrderLineItem item) => OrderInvoiceLine(
        productName: item.productName,
        productCode: item.productCode,
        caseQuantity: item.caseQuantity,
        nosPerCase: item.nosPerCase,
        totalQuantityNos: item.totalQuantityNos,
        rate: item.ratePerNo,
        originalDealerPrice: item.originalDealerPrice,
        discountPercent: item.discountValue,
        discountAmount: item.discountAmount,
        gstPercent: item.gstPercent,
        gstAmount: item.gstAmount,
        taxableAmount: item.taxableAmount,
        baseAmount: item.baseAmount,
        amount: item.finalAmount,
        quantitySummary: item.displaySummary,
      );

  factory OrderInvoiceLine.fromDetailItem(OrderDetailItem item) =>
      OrderInvoiceLine(
        productName: item.productName,
        productCode: item.productCode,
        caseQuantity: item.caseQuantity,
        nosPerCase: item.nosPerCase,
        totalQuantityNos: item.totalQuantityNos,
        rate: item.ratePerNo,
        originalDealerPrice: item.originalDealerPrice,
        discountPercent: item.discountPercentage,
        discountAmount: item.discountAmount,
        gstPercent: item.gstPercentage,
        gstAmount: item.gstAmount,
        taxableAmount: item.taxableAmount ??
            ((item.baseAmount ?? 0) - (item.discountAmount ?? 0)),
        baseAmount: item.baseAmount,
        amount: item.finalAmount ?? item.lineTotal,
        unit: item.unit,
        quantitySummary: item.quantitySummary,
      );

  factory OrderInvoiceLine.fromMap(Map<String, dynamic> item) {
    final cases = _asInt(item['case_quantity'] ?? item['cases']) ?? 1;
    final nosPerCase = _asInt(item['nos_per_case']) ?? 1;
    final totalNos = _asInt(item['total_quantity_nos'] ?? item['quantity']) ??
        (cases * nosPerCase);
    final summary = item['display_summary']?.toString();

    return OrderInvoiceLine(
      productName: item['product_name']?.toString() ?? '-',
      productCode: item['product_code']?.toString() ?? '',
      caseQuantity: cases,
      nosPerCase: nosPerCase,
      totalQuantityNos: totalNos,
      rate: _asDouble(item['rate_per_no'] ?? item['rate']) ?? 0,
      originalDealerPrice: _asDouble(item['original_dealer_price']),
      discountPercent: _asDouble(item['discount_percentage']) ?? 0,
      discountAmount: _asDouble(item['discount_amount']),
      gstPercent: _asDouble(item['gst_percentage']) ?? 0,
      gstAmount: _asDouble(item['gst_amount']),
      taxableAmount: _asDouble(
        item['taxable_amount'] ??
            item['taxable_after_transport'] ??
            item['taxable_before_transport'],
      ),
      baseAmount: _asDouble(item['base_amount']),
      amount: _asDouble(item['final_amount'] ?? item['line_total']) ?? 0,
      unit: item['unit']?.toString(),
      quantitySummary: (summary != null && summary.trim().isNotEmpty)
          ? summary
          : '$cases ${cases == 1 ? 'Case' : 'Cases'} × $nosPerCase = $totalNos Nos',
    );
  }

  static int? _asInt(Object? value) =>
      value == null ? null : int.tryParse('$value');

  static double? _asDouble(Object? value) =>
      value == null ? null : double.tryParse('$value');
}

class OrderInvoiceProductsTable extends StatelessWidget {
  const OrderInvoiceProductsTable({
    super.key,
    required this.lines,
    this.title = 'Products',
    this.showTitle = true,
    this.onEdit,
    this.onDelete,
    this.padding = EdgeInsets.zero,
  });

  final List<OrderInvoiceLine> lines;
  final String title;
  final bool showTitle;
  final void Function(int index)? onEdit;
  final void Function(int index)? onDelete;
  final EdgeInsetsGeometry padding;

  bool get _editable => onEdit != null || onDelete != null;

  static final _currency = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 2,
  );

  static final _currencyCompact = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 0,
  );

  static String money(double value, {bool compact = false}) {
    if (compact && value == value.roundToDouble()) {
      return _currencyCompact.format(value);
    }
    return _currency.format(value);
  }

  static String percent(double value) {
    if (value == value.roundToDouble()) {
      return '${value.toInt()}%';
    }
    return '${value.toStringAsFixed(1)}%';
  }

  @override
  Widget build(BuildContext context) {
    if (lines.isEmpty) {
      return Padding(
        padding: padding,
        child: Text(
          'No products.',
          style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                color: AppColors.textSecondary,
              ),
        ),
      );
    }

    return Padding(
      padding: padding,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (showTitle) ...[
            Text(title, style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: AppSpacing.sm),
          ],
          LayoutBuilder(
            builder: (context, constraints) {
              final minWidth = _editable ? 560.0 : 520.0;
              final needsScroll = constraints.maxWidth < (_editable ? 400 : 360);
              final table = _InvoiceTable(
                lines: lines,
                editable: _editable,
                onTapRow: (index) => _showLineDetails(context, lines[index]),
                onEdit: onEdit,
                onDelete: onDelete,
              );

              if (!needsScroll) return table;

              return SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: ConstrainedBox(
                  constraints: BoxConstraints(minWidth: minWidth),
                  child: SizedBox(width: minWidth, child: table),
                ),
              );
            },
          ),
        ],
      ),
    );
  }

  Future<void> _showLineDetails(
    BuildContext context,
    OrderInvoiceLine line,
  ) async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.lg,
              0,
              AppSpacing.lg,
              AppSpacing.lg,
            ),
            child: SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    line.productName,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  if (line.productCode.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(
                      line.productCode,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: AppColors.textSecondary,
                          ),
                    ),
                  ],
                  const SizedBox(height: AppSpacing.md),
                  if (line.productCode.isNotEmpty)
                    PgInvoiceRow(label: 'Product Code', value: line.productCode),
                  PgInvoiceRow(label: 'Cases', value: '${line.caseQuantity}'),
                  PgInvoiceRow(
                    label: 'Nos Per Case',
                    value: '${line.nosPerCase}',
                  ),
                  PgInvoiceRow(
                    label: 'Total Nos',
                    value: '${line.totalQuantityNos}',
                  ),
                  if ((line.unit ?? '').isNotEmpty)
                    PgInvoiceRow(label: 'Unit', value: line.unit!),
                  if (line.originalDealerPrice != null)
                    PgInvoiceRow(
                      label: 'Original Dealer Price',
                      value: money(line.originalDealerPrice!),
                    ),
                  PgInvoiceRow(
                    label: 'Rate Per No',
                    value: money(line.rate),
                  ),
                  PgInvoiceRow(
                    label: 'Discount',
                    value: line.discountAmount != null
                        ? '${percent(line.discountPercent)} (${money(line.discountAmount!)})'
                        : percent(line.discountPercent),
                  ),
                  PgInvoiceRow(
                    label: 'GST',
                    value: line.gstAmount != null
                        ? '${percent(line.gstPercent)} (${money(line.gstAmount!)})'
                        : percent(line.gstPercent),
                  ),
                  if (line.taxableAmount != null)
                    PgInvoiceRow(
                      label: 'Taxable Amount',
                      value: money(line.taxableAmount!),
                    ),
                  PgInvoiceRow(
                    label: 'Final Amount',
                    value: money(line.amount),
                    emphasize: true,
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

class OrderInvoiceSummaryBlock extends StatelessWidget {
  const OrderInvoiceSummaryBlock({
    super.key,
    required this.subtotal,
    required this.discount,
    required this.gst,
    required this.grandTotal,
    this.taxableValue,
    this.transport,
    this.title = 'Order Summary',
    this.showTitle = true,
    this.extraRows = const [],
  });

  final double subtotal;
  final double discount;
  final double gst;
  final double grandTotal;
  final double? taxableValue;
  final double? transport;
  final String title;
  final bool showTitle;
  final List<Widget> extraRows;

  @override
  Widget build(BuildContext context) {
    final taxable = taxableValue ?? (subtotal - discount);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (showTitle) ...[
          Text(title, style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: AppSpacing.sm),
        ],
        PgInvoiceRow(
          label: 'Subtotal',
          value: OrderInvoiceProductsTable.money(subtotal),
        ),
        PgInvoiceRow(
          label: 'Discount',
          value: OrderInvoiceProductsTable.money(discount),
        ),
        PgInvoiceRow(
          label: 'Taxable Value',
          value: OrderInvoiceProductsTable.money(taxable),
        ),
        PgInvoiceRow(
          label: 'GST',
          value: OrderInvoiceProductsTable.money(gst),
        ),
        if (transport != null && transport! > 0)
          PgInvoiceRow(
            label: 'Transport Charges',
            value: OrderInvoiceProductsTable.money(transport!),
          ),
        ...extraRows,
        const Divider(height: AppSpacing.lg),
        PgInvoiceRow(
          label: 'Grand Total',
          value: OrderInvoiceProductsTable.money(grandTotal),
          isTotal: true,
        ),
      ],
    );
  }
}

/// Convenience card wrapping products table + optional summary.
class OrderInvoiceProductsCard extends StatelessWidget {
  const OrderInvoiceProductsCard({
    super.key,
    required this.lines,
    this.title = 'Products',
    this.showTitle = true,
    this.summary,
    this.onEdit,
    this.onDelete,
  });

  final List<OrderInvoiceLine> lines;
  final String title;
  final bool showTitle;
  final Widget? summary;
  final void Function(int index)? onEdit;
  final void Function(int index)? onDelete;

  @override
  Widget build(BuildContext context) => PgCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            OrderInvoiceProductsTable(
              lines: lines,
              title: title,
              showTitle: showTitle,
              onEdit: onEdit,
              onDelete: onDelete,
            ),
            if (summary != null) ...[
              const SizedBox(height: AppSpacing.md),
              const Divider(height: 1),
              const SizedBox(height: AppSpacing.md),
              summary!,
            ],
          ],
        ),
      );
}

class _InvoiceTable extends StatelessWidget {
  const _InvoiceTable({
    required this.lines,
    required this.editable,
    required this.onTapRow,
    this.onEdit,
    this.onDelete,
  });

  final List<OrderInvoiceLine> lines;
  final bool editable;
  final void Function(int index) onTapRow;
  final void Function(int index)? onEdit;
  final void Function(int index)? onDelete;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        _HeaderRow(editable: editable),
        const Divider(height: 1),
        ...List.generate(lines.length, (index) {
          final line = lines[index];
          return Column(
            children: [
              _DataRow(
                index: index + 1,
                line: line,
                editable: editable,
                onTap: () => onTapRow(index),
                onEdit: onEdit == null ? null : () => onEdit!(index),
                onDelete: onDelete == null ? null : () => onDelete!(index),
              ),
              if (index != lines.length - 1)
                Divider(height: 1, color: AppColors.border.withValues(alpha: 0.7)),
            ],
          );
        }),
      ],
    );
  }
}

class _HeaderRow extends StatelessWidget {
  const _HeaderRow({required this.editable});

  final bool editable;

  @override
  Widget build(BuildContext context) {
    final style = Theme.of(context).textTheme.labelSmall?.copyWith(
          color: AppColors.textSecondary,
          fontWeight: FontWeight.w700,
          letterSpacing: 0.2,
        );

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          SizedBox(width: 22, child: Text('#', style: style)),
          Expanded(flex: 5, child: Text('Product', style: style)),
          SizedBox(
            width: 42,
            child: Text('Qty', style: style, textAlign: TextAlign.right),
          ),
          SizedBox(
            width: 54,
            child: Text('Rate', style: style, textAlign: TextAlign.right),
          ),
          SizedBox(
            width: 36,
            child: Text('Disc', style: style, textAlign: TextAlign.right),
          ),
          SizedBox(
            width: 34,
            child: Text('GST', style: style, textAlign: TextAlign.right),
          ),
          SizedBox(
            width: 72,
            child: Text('Amount', style: style, textAlign: TextAlign.right),
          ),
          SizedBox(width: editable ? 64 : 4),
        ],
      ),
    );
  }
}

class _DataRow extends StatelessWidget {
  const _DataRow({
    required this.index,
    required this.line,
    required this.editable,
    required this.onTap,
    this.onEdit,
    this.onDelete,
  });

  final int index;
  final OrderInvoiceLine line;
  final bool editable;
  final VoidCallback onTap;
  final VoidCallback? onEdit;
  final VoidCallback? onDelete;

  @override
  Widget build(BuildContext context) {
    final body = Theme.of(context).textTheme.bodySmall;
    final amountStyle = body?.copyWith(
      fontWeight: FontWeight.w700,
      color: AppColors.textPrimary,
    );
    final muted = body?.copyWith(
      color: AppColors.textSecondary,
      fontSize: 10,
      height: 1.2,
    );

    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 22,
              child: Text('$index', style: body),
            ),
            Expanded(
              flex: 5,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    line.productName,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: body?.copyWith(
                      fontWeight: FontWeight.w600,
                      color: AppColors.textPrimary,
                      height: 1.25,
                    ),
                  ),
                  if (line.productCode.isNotEmpty)
                    Text(
                      line.productCode,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: muted,
                    ),
                ],
              ),
            ),
            SizedBox(
              width: 42,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    '${line.totalQuantityNos}',
                    textAlign: TextAlign.right,
                    style: body?.copyWith(fontWeight: FontWeight.w600),
                  ),
                  Text(
                    line.shortQtyHint,
                    textAlign: TextAlign.right,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: muted,
                  ),
                ],
              ),
            ),
            SizedBox(
              width: 54,
              child: Text(
                OrderInvoiceProductsTable.money(line.rate, compact: true),
                textAlign: TextAlign.right,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: body,
              ),
            ),
            SizedBox(
              width: 36,
              child: Text(
                OrderInvoiceProductsTable.percent(line.discountPercent),
                textAlign: TextAlign.right,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: body,
              ),
            ),
            SizedBox(
              width: 34,
              child: Text(
                OrderInvoiceProductsTable.percent(line.gstPercent),
                textAlign: TextAlign.right,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: body,
              ),
            ),
            SizedBox(
              width: 72,
              child: Text(
                OrderInvoiceProductsTable.money(line.amount),
                textAlign: TextAlign.right,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: amountStyle,
              ),
            ),
            if (editable)
              SizedBox(
                width: 64,
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    if (onEdit != null)
                      InkWell(
                        onTap: onEdit,
                        borderRadius: BorderRadius.circular(16),
                        child: const Padding(
                          padding: EdgeInsets.all(4),
                          child: Icon(Icons.edit_outlined, size: 18),
                        ),
                      ),
                    if (onDelete != null)
                      InkWell(
                        onTap: onDelete,
                        borderRadius: BorderRadius.circular(16),
                        child: const Padding(
                          padding: EdgeInsets.all(4),
                          child: Icon(
                            Icons.delete_outline,
                            size: 18,
                            color: AppColors.error,
                          ),
                        ),
                      ),
                  ],
                ),
              )
            else
              const SizedBox(width: 4),
          ],
        ),
      ),
    );
  }
}
