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
    this.rateType = OrderItemRateType.priceList,
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
  final OrderItemRateType rateType;

  bool get isFixedRate => rateType == OrderItemRateType.fixedRate;

  String get rateTypeLabel => rateType.label;

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
        discountPercent: item.isFixedRate ? 0 : item.discountValue,
        discountAmount: item.isFixedRate ? 0 : item.discountAmount,
        gstPercent: item.gstPercent,
        gstAmount: item.gstAmount,
        taxableAmount: item.taxableAmount,
        baseAmount: item.baseAmount,
        amount: item.finalAmount,
        quantitySummary: item.displaySummary,
        rateType: item.rateType,
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
        discountPercent:
            item.rateType == OrderItemRateType.fixedRate
                ? 0
                : item.discountPercentage,
        discountAmount:
            item.rateType == OrderItemRateType.fixedRate
                ? 0
                : item.discountAmount,
        gstPercent: item.gstPercentage,
        gstAmount: item.gstAmount,
        taxableAmount: item.taxableAmount ??
            ((item.baseAmount ?? 0) - (item.discountAmount ?? 0)),
        baseAmount: item.baseAmount,
        amount: item.finalAmount ?? item.lineTotal,
        unit: item.unit,
        quantitySummary: item.quantitySummary,
        rateType: item.rateType,
      );

  factory OrderInvoiceLine.fromMap(Map<String, dynamic> item) {
    final cases = _asInt(item['case_quantity'] ?? item['cases']) ?? 1;
    final nosPerCase = _asInt(item['nos_per_case']) ?? 1;
    final totalNos = _asInt(item['total_quantity_nos'] ?? item['quantity']) ??
        (cases * nosPerCase);
    final summary = item['display_summary']?.toString();
    final rateType = OrderItemRateType.fromOrderJson(item);
    final discountPercent = rateType == OrderItemRateType.fixedRate
        ? 0.0
        : (_asDouble(item['discount_percentage']) ?? 0);
    final discountAmount = rateType == OrderItemRateType.fixedRate
        ? 0.0
        : _asDouble(item['discount_amount']);

    return OrderInvoiceLine(
      productName: item['product_name']?.toString() ?? '-',
      productCode: item['product_code']?.toString() ?? '',
      caseQuantity: cases,
      nosPerCase: nosPerCase,
      totalQuantityNos: totalNos,
      rate: _asDouble(item['rate_per_no'] ?? item['rate']) ?? 0,
      originalDealerPrice: _asDouble(item['original_dealer_price']),
      discountPercent: discountPercent,
      discountAmount: discountAmount,
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
      rateType: rateType,
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
    this.freezeProductColumn = false,
    this.spaciousLayout = false,
    this.showSplitTaxColumns = false,
  });

  final List<OrderInvoiceLine> lines;
  final String title;
  final bool showTitle;
  final void Function(int index)? onEdit;
  final void Function(int index)? onDelete;
  final EdgeInsetsGeometry padding;
  /// When true, Product name/code stay fixed while metrics scroll horizontally.
  final bool freezeProductColumn;
  /// Slightly wider columns and roomier row padding (Order Details).
  final bool spaciousLayout;
  /// When true with frozen layout: Amt w/o GST, CGST, SGST, Total (Manager).
  final bool showSplitTaxColumns;

  bool get _editable => onEdit != null || onDelete != null;

  /// Fixed left pane: Sr (#) + Product name/code.
  static const double frozenProductWidth = 156;
  static const double frozenProductWidthSpacious = 176;

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

    final layout = showSplitTaxColumns
        ? _FrozenTableLayout.spaciousSplitTax
        : spaciousLayout
            ? _FrozenTableLayout.spacious
            : _FrozenTableLayout.compact;

    return Padding(
      padding: padding,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (showTitle) ...[
            Text(title, style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: AppSpacing.sm),
          ],
          if (freezeProductColumn)
            _FrozenInvoiceTable(
              lines: lines,
              editable: _editable,
              layout: layout,
              showSplitTaxColumns: showSplitTaxColumns,
              onTapRow: (index) => _showLineDetails(context, lines[index]),
              onEdit: onEdit,
              onDelete: onDelete,
            )
          else
            LayoutBuilder(
              builder: (context, constraints) {
                final minWidth = _editable ? 560.0 : 520.0;
                final needsScroll =
                    constraints.maxWidth < (_editable ? 400 : 360);
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
                    label: 'Rate Type',
                    value: line.rateTypeLabel,
                  ),
                  PgInvoiceRow(
                    label: 'Rate Per No',
                    value: money(line.rate),
                  ),
                  PgInvoiceRow(
                    label: 'Discount',
                    value: line.isFixedRate
                        ? '—'
                        : (line.discountAmount != null
                            ? '${percent(line.discountPercent)} (${money(line.discountAmount!)})'
                            : percent(line.discountPercent)),
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
    this.vehicleNo,
    this.transportTypeLabel,
    this.originalGrandTotal,
    this.transportAdjustment,
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
  final String? vehicleNo;
  final String? transportTypeLabel;
  final double? originalGrandTotal;
  final double? transportAdjustment;
  final String title;
  final bool showTitle;
  final List<Widget> extraRows;

  bool get _hasBillingTransport {
    return (transportTypeLabel ?? '').trim().isNotEmpty;
  }

  factory OrderInvoiceSummaryBlock.fromOrderMap(
    Map<String, dynamic> order, {
    Map<String, dynamic>? calculation,
    String title = 'Order Summary',
    bool showTitle = true,
  }) {
    final calc = calculation ?? const <String, dynamic>{};
    return OrderInvoiceSummaryBlock(
      title: title,
      showTitle: showTitle,
      subtotal:
          double.tryParse(
            '${calc['gross_amount'] ?? order['gross_amount'] ?? order['subtotal'] ?? 0}',
          ) ??
          0,
      discount:
          double.tryParse(
            '${calc['total_discount'] ?? order['total_discount'] ?? order['discount_amount'] ?? 0}',
          ) ??
          0,
      gst:
          double.tryParse(
            '${order['gst_amount'] ?? calc['total_gst'] ?? order['total_gst'] ?? 0}',
          ) ??
          0,
      grandTotal:
          double.tryParse(
            '${order['final_grand_total'] ?? order['grand_total'] ?? 0}',
          ) ??
          0,
      taxableValue: order['taxable_amount_after_transport'] == null
          ? null
          : double.tryParse('${order['taxable_amount_after_transport']}'),
      transport: order['transport_amount'] == null &&
              order['transport_charges'] == null
          ? null
          : double.tryParse(
                '${order['transport_amount'] ?? order['transport_charges']}',
              ) ??
              0,
      vehicleNo: (order['vehicle_no'] ?? order['vehicle_number'])?.toString(),
      transportTypeLabel: order['transport_charge_type_label']?.toString(),
      originalGrandTotal: order['original_grand_total'] == null
          ? null
          : double.tryParse('${order['original_grand_total']}'),
      transportAdjustment: order['transport_adjustment'] == null
          ? null
          : double.tryParse('${order['transport_adjustment']}'),
    );
  }

  @override
  Widget build(BuildContext context) {
    final taxable = taxableValue ?? (subtotal - discount);
    final vehicle = (vehicleNo ?? '').trim();

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
        if (_hasBillingTransport) ...[
          PgInvoiceRow(
            label: 'Transport Type',
            value: transportTypeLabel!,
          ),
          PgInvoiceRow(
            label: 'Transport Charges',
            value: _formatSignedCharges(
              transport ?? 0,
              transportAdjustment,
              transportTypeLabel,
            ),
          ),
        ],
        PgInvoiceRow(
          label: 'Taxable Value',
          value: OrderInvoiceProductsTable.money(taxable),
        ),
        PgInvoiceRow(
          label: 'GST',
          value: OrderInvoiceProductsTable.money(gst),
        ),
        if (_hasBillingTransport) ...[
          if (vehicle.isNotEmpty)
            PgInvoiceRow(label: 'Vehicle No', value: vehicle),
        ] else if (transport != null && transport! > 0) ...[
          if (vehicle.isNotEmpty)
            PgInvoiceRow(label: 'Vehicle No', value: vehicle),
          PgInvoiceRow(
            label: 'Transport Charges',
            value: OrderInvoiceProductsTable.money(transport!),
          ),
        ],
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

  static String _formatSignedCharges(
    double charges,
    double? adjustment,
    String? typeLabel,
  ) {
    final formatted = OrderInvoiceProductsTable.money(charges.abs());
    if (adjustment != null) {
      if (adjustment < 0) return '- $formatted';
      return '+ $formatted';
    }

    final type = (typeLabel ?? '').toLowerCase();
    if (type.contains('company')) return '- $formatted';
    if (type.contains('extra')) return '+ $formatted';
    return formatted;
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
    this.freezeProductColumn = false,
    this.showTotalCases = false,
    this.spaciousLayout = false,
    this.showSplitTaxColumns = false,
  });

  /// Shared detail/review presentation for Employee, Manager, and Production.
  factory OrderInvoiceProductsCard.sharedReview({
    Key? key,
    required List<OrderInvoiceLine> lines,
    String title = 'Products',
    bool showTitle = true,
    Widget? summary,
    void Function(int index)? onEdit,
    void Function(int index)? onDelete,
  }) {
    return OrderInvoiceProductsCard(
      key: key,
      lines: lines,
      title: title,
      showTitle: showTitle,
      summary: summary,
      onEdit: onEdit,
      onDelete: onDelete,
      freezeProductColumn: true,
      showTotalCases: true,
      spaciousLayout: true,
      showSplitTaxColumns: true,
    );
  }

  final List<OrderInvoiceLine> lines;
  final String title;
  final bool showTitle;
  final Widget? summary;
  final void Function(int index)? onEdit;
  final void Function(int index)? onDelete;
  final bool freezeProductColumn;
  final bool showTotalCases;
  final bool spaciousLayout;
  final bool showSplitTaxColumns;

  int get _totalCases =>
      lines.fold<int>(0, (sum, line) => sum + line.caseQuantity);

  @override
  Widget build(BuildContext context) => PgCard(
        // Near full-width product table: tighter horizontal inset than default cards.
        padding: spaciousLayout
            ? const EdgeInsets.fromLTRB(6, 12, 6, 12)
            : null,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            OrderInvoiceProductsTable(
              lines: lines,
              title: title,
              showTitle: showTitle,
              onEdit: onEdit,
              onDelete: onDelete,
              freezeProductColumn: freezeProductColumn,
              spaciousLayout: spaciousLayout,
              showSplitTaxColumns: showSplitTaxColumns,
            ),
            if (showTotalCases) ...[
              const SizedBox(height: AppSpacing.md),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4),
                child: Text(
                  'Total Cases: $_totalCases',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                      ),
                ),
              ),
            ],
            if (summary != null) ...[
              const SizedBox(height: AppSpacing.md),
              const Divider(height: 1),
              const SizedBox(height: AppSpacing.md),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4),
                child: summary!,
              ),
            ],
          ],
        ),
      );
}

class _FrozenTableLayout {
  const _FrozenTableLayout({
    required this.frozenWidth,
    required this.srWidth,
    required this.showSr,
    required this.casesWidth,
    required this.qtyWidth,
    required this.rateWidth,
    required this.discWidth,
    required this.gstWidth,
    required this.taxableWidth,
    required this.cgstWidth,
    required this.sgstWidth,
    required this.amountWidth,
    required this.actionsWidth,
    required this.headerVertical,
    required this.rowVertical,
    required this.columnGap,
  });

  final double frozenWidth;
  final double srWidth;
  final bool showSr;
  final double casesWidth;
  final double qtyWidth;
  final double rateWidth;
  final double discWidth;
  final double gstWidth;
  final double taxableWidth;
  final double cgstWidth;
  final double sgstWidth;
  final double amountWidth;
  final double actionsWidth;
  final double headerVertical;
  final double rowVertical;
  final double columnGap;

  static const compact = _FrozenTableLayout(
    frozenWidth: OrderInvoiceProductsTable.frozenProductWidth,
    srWidth: 22,
    showSr: true,
    casesWidth: 44,
    qtyWidth: 42,
    rateWidth: 54,
    discWidth: 36,
    gstWidth: 34,
    taxableWidth: 0,
    cgstWidth: 0,
    sgstWidth: 0,
    amountWidth: 72,
    actionsWidth: 64,
    headerVertical: 6,
    rowVertical: 8,
    columnGap: 0,
  );

  static const spacious = _FrozenTableLayout(
    frozenWidth: OrderInvoiceProductsTable.frozenProductWidthSpacious,
    srWidth: 26,
    showSr: true,
    casesWidth: 52,
    qtyWidth: 52,
    rateWidth: 64,
    discWidth: 48,
    gstWidth: 48,
    taxableWidth: 0,
    cgstWidth: 0,
    sgstWidth: 0,
    amountWidth: 88,
    actionsWidth: 64,
    headerVertical: 10,
    rowVertical: 12,
    columnGap: 6,
  );

  /// Manager / Production / Employee shared review: split tax + taxable amount columns.
  static const spaciousSplitTax = _FrozenTableLayout(
    frozenWidth: 172,
    srWidth: 24,
    showSr: true,
    casesWidth: 52,
    qtyWidth: 52,
    rateWidth: 68,
    discWidth: 56,
    gstWidth: 0,
    taxableWidth: 96,
    cgstWidth: 68,
    sgstWidth: 68,
    amountWidth: 92,
    actionsWidth: 64,
    headerVertical: 12,
    rowVertical: 14,
    columnGap: 8,
  );

  double scrollContentWidth({
    required bool editable,
    required bool splitTax,
  }) {
    final metrics = casesWidth +
        qtyWidth +
        rateWidth +
        discWidth +
        (splitTax
            ? (taxableWidth + cgstWidth + sgstWidth)
            : gstWidth) +
        amountWidth +
        (editable ? actionsWidth : 4);
    final gaps = splitTax ? 7 : 5;
    return metrics + (columnGap * gaps);
  }
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

/// Products table with frozen Product / Code and horizontally scrollable metrics.
class _FrozenInvoiceTable extends StatefulWidget {
  const _FrozenInvoiceTable({
    required this.lines,
    required this.editable,
    required this.layout,
    required this.showSplitTaxColumns,
    required this.onTapRow,
    this.onEdit,
    this.onDelete,
  });

  final List<OrderInvoiceLine> lines;
  final bool editable;
  final _FrozenTableLayout layout;
  final bool showSplitTaxColumns;
  final void Function(int index) onTapRow;
  final void Function(int index)? onEdit;
  final void Function(int index)? onDelete;

  @override
  State<_FrozenInvoiceTable> createState() => _FrozenInvoiceTableState();
}

class _FrozenInvoiceTableState extends State<_FrozenInvoiceTable> {
  late final ScrollController _headerScroll;
  late final List<ScrollController> _rowScrolls;
  bool _syncing = false;

  double get _scrollContentWidth => widget.layout.scrollContentWidth(
        editable: widget.editable,
        splitTax: widget.showSplitTaxColumns,
      );

  @override
  void initState() {
    super.initState();
    _headerScroll = ScrollController();
    _headerScroll.addListener(() => _syncFrom(_headerScroll));
    _rowScrolls = List.generate(widget.lines.length, (_) {
      final c = ScrollController();
      c.addListener(() => _syncFrom(c));
      return c;
    });
  }

  @override
  void didUpdateWidget(covariant _FrozenInvoiceTable oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.lines.length == widget.lines.length) return;
    for (final c in _rowScrolls) {
      c.dispose();
    }
    _rowScrolls
      ..clear()
      ..addAll(List.generate(widget.lines.length, (_) {
        final c = ScrollController();
        c.addListener(() => _syncFrom(c));
        return c;
      }));
  }

  void _syncFrom(ScrollController source) {
    if (_syncing || !source.hasClients) return;
    _syncing = true;
    final offset = source.offset;
    void jump(ScrollController c) {
      if (c == source || !c.hasClients) return;
      if ((c.offset - offset).abs() < 0.5) return;
      c.jumpTo(offset.clamp(0.0, c.position.maxScrollExtent));
    }

    jump(_headerScroll);
    for (final c in _rowScrolls) {
      jump(c);
    }
    _syncing = false;
  }

  @override
  void dispose() {
    _headerScroll.dispose();
    for (final c in _rowScrolls) {
      c.dispose();
    }
    super.dispose();
  }

  BoxDecoration get _frozenDecoration => BoxDecoration(
        color: Colors.white,
        border: Border(
          right: BorderSide(
            color: AppColors.border.withValues(alpha: 0.9),
            width: 1,
          ),
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.06),
            offset: const Offset(3, 0),
            blurRadius: 4,
          ),
        ],
      );

  @override
  Widget build(BuildContext context) {
    final layout = widget.layout;
    final headerStyle = Theme.of(context).textTheme.labelSmall?.copyWith(
          color: AppColors.textSecondary,
          fontWeight: FontWeight.w700,
          letterSpacing: 0.15,
          fontSize: 11,
          height: 1.15,
        );

    return Column(
      children: [
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              DecoratedBox(
                decoration: _frozenDecoration,
                child: SizedBox(
                  width: layout.frozenWidth,
                  child: Padding(
                    padding: EdgeInsets.fromLTRB(
                      0,
                      layout.headerVertical,
                      8,
                      layout.headerVertical,
                    ),
                    child: Row(
                      children: [
                        if (layout.showSr)
                          SizedBox(
                            width: layout.srWidth,
                            child: Text('#', style: headerStyle),
                          ),
                        Expanded(
                          child: Text('Product', style: headerStyle),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              Expanded(
                child: SingleChildScrollView(
                  controller: _headerScroll,
                  scrollDirection: Axis.horizontal,
                  physics: const ClampingScrollPhysics(),
                  child: SizedBox(
                    width: _scrollContentWidth,
                    child: Padding(
                      padding: EdgeInsets.symmetric(
                        vertical: layout.headerVertical,
                      ),
                      child: _MetricsHeaderRow(
                        editable: widget.editable,
                        layout: layout,
                        style: headerStyle,
                        splitTax: widget.showSplitTaxColumns,
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
        const Divider(height: 1),
        ...List.generate(widget.lines.length, (index) {
          final line = widget.lines[index];
          return Column(
            children: [
              IntrinsicHeight(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    DecoratedBox(
                      decoration: _frozenDecoration,
                      child: SizedBox(
                        width: layout.frozenWidth,
                        child: InkWell(
                          onTap: () => widget.onTapRow(index),
                          child: Padding(
                            padding: EdgeInsets.fromLTRB(
                              0,
                              layout.rowVertical,
                              8,
                              layout.rowVertical,
                            ),
                            child: _FrozenProductCell(
                              index: index + 1,
                              line: line,
                              srWidth: layout.srWidth,
                              showSr: layout.showSr,
                            ),
                          ),
                        ),
                      ),
                    ),
                    Expanded(
                      child: SingleChildScrollView(
                        controller: _rowScrolls[index],
                        scrollDirection: Axis.horizontal,
                        physics: const ClampingScrollPhysics(),
                        child: SizedBox(
                          width: _scrollContentWidth,
                          child: InkWell(
                            onTap: () => widget.onTapRow(index),
                            child: Padding(
                              padding: EdgeInsets.symmetric(
                                vertical: layout.rowVertical,
                              ),
                              child: _MetricsDataRow(
                                line: line,
                                editable: widget.editable,
                                layout: layout,
                                splitTax: widget.showSplitTaxColumns,
                                onEdit: widget.onEdit == null
                                    ? null
                                    : () => widget.onEdit!(index),
                                onDelete: widget.onDelete == null
                                    ? null
                                    : () => widget.onDelete!(index),
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              if (index != widget.lines.length - 1)
                Divider(
                  height: 1,
                  color: AppColors.border.withValues(alpha: 0.7),
                ),
            ],
          );
        }),
      ],
    );
  }
}

class _FrozenProductCell extends StatelessWidget {
  const _FrozenProductCell({
    required this.index,
    required this.line,
    required this.srWidth,
    required this.showSr,
  });

  final int index;
  final OrderInvoiceLine line;
  final double srWidth;
  final bool showSr;

  @override
  Widget build(BuildContext context) {
    final body = Theme.of(context).textTheme.bodySmall;
    final muted = body?.copyWith(
      color: AppColors.textSecondary,
      fontSize: 11,
      height: 1.25,
    );

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (showSr)
          SizedBox(
            width: srWidth,
            child: Text('$index', style: body),
          ),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                line.productName,
                maxLines: 2,
                softWrap: true,
                overflow: TextOverflow.ellipsis,
                style: body?.copyWith(
                  fontWeight: FontWeight.w600,
                  color: AppColors.textPrimary,
                  height: 1.3,
                ),
              ),
              if (line.productCode.isNotEmpty) ...[
                const SizedBox(height: 2),
                Text(
                  line.productCode,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: muted,
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _MetricsHeaderRow extends StatelessWidget {
  const _MetricsHeaderRow({
    required this.editable,
    required this.layout,
    required this.style,
    required this.splitTax,
  });

  final bool editable;
  final _FrozenTableLayout layout;
  final TextStyle? style;
  final bool splitTax;

  Widget _col(double width, String label) => SizedBox(
        width: width,
        child: Text(
          label,
          style: style,
          textAlign: TextAlign.right,
          maxLines: 2,
          softWrap: true,
        ),
      );

  @override
  Widget build(BuildContext context) {
    final gap = layout.columnGap;
    return Row(
      children: [
        _col(layout.casesWidth, 'Cases'),
        if (gap > 0) SizedBox(width: gap),
        _col(layout.qtyWidth, 'Qty'),
        if (gap > 0) SizedBox(width: gap),
        _col(layout.rateWidth, 'Rate'),
        if (gap > 0) SizedBox(width: gap),
        _col(layout.discWidth, splitTax ? 'Disc %' : 'Disc'),
        if (gap > 0) SizedBox(width: gap),
        if (splitTax) ...[
          _col(layout.taxableWidth, 'Amt w/o GST'),
          if (gap > 0) SizedBox(width: gap),
          _col(layout.cgstWidth, 'CGST'),
          if (gap > 0) SizedBox(width: gap),
          _col(layout.sgstWidth, 'SGST'),
        ] else
          _col(layout.gstWidth, 'GST'),
        if (gap > 0) SizedBox(width: gap),
        _col(layout.amountWidth, splitTax ? 'Total' : 'Amount'),
        SizedBox(width: editable ? layout.actionsWidth : 4),
      ],
    );
  }
}

class _MetricsDataRow extends StatelessWidget {
  const _MetricsDataRow({
    required this.line,
    required this.editable,
    required this.layout,
    required this.splitTax,
    this.onEdit,
    this.onDelete,
  });

  final OrderInvoiceLine line;
  final bool editable;
  final _FrozenTableLayout layout;
  final bool splitTax;
  final VoidCallback? onEdit;
  final VoidCallback? onDelete;

  double get _gstAmount {
    if (line.gstAmount != null) return line.gstAmount!;
    final taxable = _taxableAmount;
    return taxable * line.gstPercent / 100;
  }

  double get _taxableAmount {
    if (line.taxableAmount != null) return line.taxableAmount!;
    if (line.baseAmount != null) {
      return line.baseAmount! - (line.discountAmount ?? 0);
    }
    return (line.amount - _gstAmountFromFinal).clamp(0, double.infinity);
  }

  double get _gstAmountFromFinal {
    if (line.gstAmount != null) return line.gstAmount!;
    if (line.gstPercent <= 0) return 0;
    return line.amount * line.gstPercent / (100 + line.gstPercent);
  }

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
    final gap = layout.columnGap;
    final taxable = _taxableAmount;
    final gst = _gstAmount;
    final cgst = gst / 2;
    final sgst = gst / 2;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: layout.casesWidth,
          child: Text(
            '${line.caseQuantity}',
            textAlign: TextAlign.right,
            style: body?.copyWith(fontWeight: FontWeight.w600),
          ),
        ),
        if (gap > 0) SizedBox(width: gap),
        SizedBox(
          width: layout.qtyWidth,
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
        if (gap > 0) SizedBox(width: gap),
        SizedBox(
          width: layout.rateWidth,
          child: Text(
            OrderInvoiceProductsTable.money(line.rate, compact: true),
            textAlign: TextAlign.right,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: body,
          ),
        ),
        if (gap > 0) SizedBox(width: gap),
        SizedBox(
          width: layout.discWidth,
          child: Text(
            line.isFixedRate
                ? '—'
                : OrderInvoiceProductsTable.percent(line.discountPercent),
            textAlign: TextAlign.right,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: body,
          ),
        ),
        if (gap > 0) SizedBox(width: gap),
        if (splitTax) ...[
          SizedBox(
            width: layout.taxableWidth,
            child: Text(
              OrderInvoiceProductsTable.money(taxable),
              textAlign: TextAlign.right,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: body,
            ),
          ),
          if (gap > 0) SizedBox(width: gap),
          SizedBox(
            width: layout.cgstWidth,
            child: Text(
              OrderInvoiceProductsTable.money(cgst),
              textAlign: TextAlign.right,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: body,
            ),
          ),
          if (gap > 0) SizedBox(width: gap),
          SizedBox(
            width: layout.sgstWidth,
            child: Text(
              OrderInvoiceProductsTable.money(sgst),
              textAlign: TextAlign.right,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: body,
            ),
          ),
        ] else
          SizedBox(
            width: layout.gstWidth,
            child: Text(
              OrderInvoiceProductsTable.percent(line.gstPercent),
              textAlign: TextAlign.right,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: body,
            ),
          ),
        if (gap > 0) SizedBox(width: gap),
        SizedBox(
          width: layout.amountWidth,
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
            width: layout.actionsWidth,
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
                  Text(
                    line.rateTypeLabel,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: muted?.copyWith(
                      color: AppColors.primary,
                      fontWeight: FontWeight.w600,
                      fontSize: 10,
                    ),
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
                line.isFixedRate
                    ? '—'
                    : OrderInvoiceProductsTable.percent(line.discountPercent),
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
