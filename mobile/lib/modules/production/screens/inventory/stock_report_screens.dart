import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/api/api_errors.dart';
import '../../../../core/design/app_colors.dart';
import '../../../../core/design/app_spacing.dart';
import '../../../../core/storage/session_store.dart';
import '../../../../core/widgets/design/pg_empty_state.dart';
import '../../../../core/widgets/role_shell_widgets.dart';
import '../../../auth/providers/auth_controller.dart';
import '../../api/inventory_production_api.dart';
import 'stock_item_ledger_pdf_screen.dart';
import 'stock_report_pdf_screen.dart';

/// Inventory Stock Report — qty / value / ledger live here (not on Masters).
class StockReportScreen extends StatefulWidget {
  const StockReportScreen({
    super.key,
    required this.auth,
    this.initialStatus,
    this.initialType,
  });

  final AuthController auth;
  final String? initialStatus;
  final String? initialType;

  @override
  State<StockReportScreen> createState() => _StockReportScreenState();
}

class _StockReportScreenState extends State<StockReportScreen> {
  final _search = TextEditingController();
  final _scroll = ScrollController();
  String? _inventoryType;
  String? _statusFilter;
  late Future<Map<String, dynamic>> _future;
  final List<Map<String, dynamic>> _items = [];
  Map<String, dynamic>? _lastData;
  int _page = 1;
  bool _loadingMore = false;
  bool _hasMore = true;

  static final _inr = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 0,
  );

  InventoryProductionApi get _api => InventoryProductionApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  bool get _isRawView =>
      _inventoryType == null || _inventoryType == 'raw_material';

  @override
  void initState() {
    super.initState();
    // Default RM stock view for Production Supervisor Inventory.
    _inventoryType = widget.initialType ?? 'raw_material';
    _statusFilter = widget.initialStatus;
    _future = _load(reset: true);
    _scroll.addListener(_onScroll);
  }

  @override
  void dispose() {
    _search.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<Map<String, dynamic>> _load({bool reset = false}) async {
    if (reset) {
      _page = 1;
      _hasMore = true;
      _items.clear();
    }
    final data = await _api.stockReport(
      inventoryType: _inventoryType,
      search: _search.text.trim(),
      stockStatusFilter: _statusFilter,
      page: _page,
    );
    final rows = (data['items'] as List?)
            ?.map((e) => Map<String, dynamic>.from(e as Map))
            .toList() ??
        const [];
    _items.addAll(rows);
    final meta = Map<String, dynamic>.from(data['meta'] as Map? ?? {});
    final lastPage = int.tryParse('${meta['last_page'] ?? 1}') ?? 1;
    _hasMore = _page < lastPage;
    _lastData = data;
    return data;
  }

  Future<void> _reload() async {
    setState(() => _future = _load(reset: true));
    await _future;
  }

  Future<void> _onScroll() async {
    if (!_hasMore || _loadingMore) return;
    if (_scroll.position.pixels < _scroll.position.maxScrollExtent - 200) {
      return;
    }
    setState(() => _loadingMore = true);
    _page += 1;
    try {
      await _load();
    } catch (_) {
      _page -= 1;
    } finally {
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  double? _parseMoney(dynamic raw, {required String context}) {
    if (raw == null) return null;
    if (raw is num) {
      final v = raw.toDouble();
      return v.isNaN ? null : v;
    }
    final text = '$raw'.trim().replaceAll(',', '');
    if (text.isEmpty || text.toLowerCase() == 'null') return null;
    final parsed = double.tryParse(text);
    if (parsed == null) {
      if (kDebugMode) {
        debugPrint('Stock value parse failed ($context): raw=$raw');
      }
      return null;
    }
    return parsed.isNaN ? null : parsed;
  }

  String _formatInr(num? value) {
    if (value == null || value.isNaN) return _inr.format(0);
    return _inr.format(value);
  }

  String _formatQty(dynamic qty, dynamic unit) {
    final parsed = double.tryParse('$qty');
    final qtyText = parsed == null
        ? '$qty'
        : (parsed == parsed.roundToDouble()
            ? '${parsed.toInt()}'
            : parsed
                .toStringAsFixed(3)
                .replaceFirst(RegExp(r'0+$'), '')
                .replaceFirst(RegExp(r'\.$'), ''));
    final unitText = '${unit ?? ''}'.trim();
    return unitText.isEmpty ? qtyText : '$qtyText $unitText';
  }

  String _ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  String _sanitizeFilenamePart(String value) {
    final cleaned = value
        .trim()
        .replaceAll(RegExp(r'[^\w\-]+'), '_')
        .replaceAll(RegExp(r'_+'), '_')
        .replaceAll(RegExp(r'^_|_$'), '');
    return cleaned.isEmpty ? 'item' : cleaned;
  }

  /// Optional report-level date filter (Stock Report currently has none).
  ({DateTime from, DateTime to})? get _activeStockReportDateFilter => null;

  Future<void> _openLedger(Map<String, dynamic> item) async {
    final itemType = '${item['inventory_type_key'] ?? ''}';
    final itemId = int.tryParse('${item['item_id'] ?? item['id'] ?? 0}') ?? 0;
    if (itemId <= 0 || itemType.isEmpty) return;

    final itemCode = _sanitizeFilenamePart(
      '${item['code'] ?? item['material_code'] ?? itemId}',
    );
    final itemName = '${item['name'] ?? item['material_name'] ?? 'Item'}';

    final active = _activeStockReportDateFilter;
    late DateTime from;
    late DateTime to;
    if (active != null) {
      from = active.from;
      to = active.to;
    } else {
      final now = DateTime.now();
      final defaults = await showDialog<({DateTime from, DateTime to})>(
        context: context,
        builder: (ctx) => _LedgerDateRangeDialog(
          initialFrom: DateTime(now.year, now.month, 1),
          initialTo: DateTime(now.year, now.month, now.day),
          itemLabel: itemName,
        ),
      );
      if (defaults == null || !mounted) return;
      from = defaults.from;
      to = defaults.to;
    }

    await _openLedgerPdf(
      itemType: itemType,
      itemId: itemId,
      itemCode: itemCode,
      from: from,
      to: to,
    );
  }

  Future<void> _openLedgerPdf({
    required String itemType,
    required int itemId,
    required String itemCode,
    required DateTime from,
    required DateTime to,
  }) async {
    if (!mounted) return;

    final fromYmd = _ymd(from);
    final toYmd = _ymd(to);

    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => StockItemLedgerPdfScreen(
          api: _api,
          itemType: itemType,
          itemId: itemId,
          itemCode: itemCode,
          fromYmd: fromYmd,
          toYmd: toYmd,
        ),
      ),
    );
  }

  bool _exportingPdf = false;

  Future<void> _exportStockReportPdf() async {
    if (!mounted || _exportingPdf) return;

    if (_items.isEmpty) {
      final proceed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Export PDF'),
          content: const Text(
            'No inventory records found for the selected filters. '
            'Export an empty report?',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.of(ctx).pop(true),
              child: const Text('Export'),
            ),
          ],
        ),
      );
      if (proceed != true || !mounted) return;
    }

    setState(() => _exportingPdf = true);
    try {
      if (!mounted) return;
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => StockReportPdfScreen(
            api: _api,
            inventoryType: _inventoryType,
            search: _search.text.trim().isEmpty ? null : _search.text.trim(),
            stockStatusFilter: _statusFilter,
          ),
        ),
      );
    } finally {
      if (mounted) setState(() => _exportingPdf = false);
    }
  }

  double? _totalBarValue(Map<String, dynamic> data) {
    final summary = data['summary'] is Map
        ? Map<String, dynamic>.from(data['summary'] as Map)
        : const <String, dynamic>{};
    if (_inventoryType == 'raw_material' || _inventoryType == null) {
      return _parseMoney(
        summary['total_raw_material_value'] ?? summary['filtered_stock_value'],
        context: 'summary.total_raw_material_value',
      );
    }
    return _parseMoney(
      summary['filtered_stock_value'],
      context: 'summary.filtered_stock_value',
    );
  }

  String _totalBarLabel() {
    return switch (_inventoryType) {
      'raw_material' || null => 'Total Raw Material Value',
      'packaging_material' => 'Total Packaging Value',
      'semi_finished' => 'Total Semi-Finished Value',
      'finished_product' => 'Total Finished Product Value',
      _ => 'Total Stock Value',
    };
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'Inventory Stock Report',
        auth: widget.auth,
        actions: [
          IconButton(
            tooltip: 'Export PDF',
            onPressed: _exportingPdf ? null : _exportStockReportPdf,
            icon: Icon(
              Icons.picture_as_pdf_outlined,
              color: _exportingPdf
                  ? AppColors.textMuted
                  : AppColors.textPrimary,
            ),
          ),
        ],
      ),
      body: Column(
        children: [
          _filters(),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _reload,
              child: FutureBuilder<Map<String, dynamic>>(
                future: _future,
                builder: (context, snapshot) {
                  if (snapshot.connectionState == ConnectionState.waiting &&
                      _items.isEmpty) {
                    return const PgLoadingState();
                  }
                  if (snapshot.hasError && _items.isEmpty) {
                    return PgErrorState(
                      message: errorMessage(snapshot.error),
                      onRetry: _reload,
                    );
                  }

                  final data = _lastData ?? snapshot.data ?? {};
                  final canCosts =
                      widget.auth.permissions.canViewProductionCosts ||
                          data['can_view_costs'] == true;
                  final totalValue = canCosts ? _totalBarValue(data) : null;

                  if (_items.isEmpty) {
                    return Column(
                      children: [
                        const Expanded(
                          child: PgEmptyState(message: 'No stock rows found.'),
                        ),
                        if (canCosts) _totalBar(totalValue),
                      ],
                    );
                  }

                  return Column(
                    children: [
                      Expanded(
                        child: Column(
                          children: [
                            _tableHeader(context, showValue: canCosts),
                            Expanded(
                              child: ListView.builder(
                                controller: _scroll,
                                padding: EdgeInsets.zero,
                                itemCount: _items.length + (_loadingMore ? 1 : 0),
                                itemBuilder: (context, index) {
                                  if (index >= _items.length) {
                                    return const Padding(
                                      padding: EdgeInsets.all(16),
                                      child: Center(
                                        child: CircularProgressIndicator(),
                                      ),
                                    );
                                  }
                                  return _tableRow(
                                    context,
                                    _items[index],
                                    zebra: index.isOdd,
                                    showValue: canCosts,
                                  );
                                },
                              ),
                            ),
                          ],
                        ),
                      ),
                      if (canCosts) _totalBar(totalValue),
                    ],
                  );
                },
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _filters() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.screenPadding,
        AppSpacing.sm,
        AppSpacing.screenPadding,
        AppSpacing.xs,
      ),
      child: Column(
        children: [
          Row(
            children: [
              Expanded(
                flex: 3,
                child: SizedBox(
                  height: 40,
                  child: TextField(
                    controller: _search,
                    style: const TextStyle(fontSize: 13),
                    decoration: InputDecoration(
                      isDense: true,
                      hintText: 'Search',
                      contentPadding: const EdgeInsets.symmetric(
                        horizontal: 10,
                        vertical: 8,
                      ),
                      prefixIcon: const Icon(Icons.search, size: 18),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(8),
                      ),
                    ),
                    onSubmitted: (_) => _reload(),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                flex: 2,
                child: SizedBox(
                  height: 40,
                  child: DropdownButtonFormField<String?>(
                    value: _statusFilter,
                    isDense: true,
                    decoration: InputDecoration(
                      isDense: true,
                      contentPadding: const EdgeInsets.symmetric(
                        horizontal: 10,
                        vertical: 8,
                      ),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(8),
                      ),
                    ),
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: AppColors.textPrimary,
                        ),
                    items: const [
                      DropdownMenuItem(value: null, child: Text('All')),
                      DropdownMenuItem(
                        value: 'in_stock',
                        child: Text('In Stock'),
                      ),
                      DropdownMenuItem(
                        value: 'low_stock',
                        child: Text('Low'),
                      ),
                      DropdownMenuItem(
                        value: 'out_of_stock',
                        child: Text('Out'),
                      ),
                    ],
                    onChanged: (v) {
                      setState(() => _statusFilter = v);
                      _reload();
                    },
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          SizedBox(
            height: 40,
            child: DropdownButtonFormField<String?>(
              value: _inventoryType,
              isDense: true,
              decoration: InputDecoration(
                isDense: true,
                labelText: 'Inventory type',
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 8,
                ),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(8),
                ),
              ),
              items: const [
                DropdownMenuItem(
                  value: 'raw_material',
                  child: Text('Raw Material'),
                ),
                DropdownMenuItem(
                  value: 'packaging_material',
                  child: Text('Packaging'),
                ),
                DropdownMenuItem(
                  value: 'semi_finished',
                  child: Text('Semi-Finished'),
                ),
                DropdownMenuItem(
                  value: 'finished_product',
                  child: Text('Finished Product'),
                ),
                DropdownMenuItem(value: null, child: Text('All')),
              ],
              onChanged: (v) {
                setState(() => _inventoryType = v);
                _reload();
              },
            ),
          ),
        ],
      ),
    );
  }

  Widget _tableHeader(BuildContext context, {required bool showValue}) {
    final narrow = MediaQuery.sizeOf(context).width < 380;
    final style = Theme.of(context).textTheme.labelSmall?.copyWith(
          fontWeight: FontWeight.w700,
          color: AppColors.textSecondary,
          letterSpacing: 0.2,
        );

    return Container(
      decoration: const BoxDecoration(
        color: AppColors.background,
        border: Border(
          bottom: BorderSide(color: AppColors.border),
          top: BorderSide(color: AppColors.border),
        ),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      child: Row(
        children: [
          Expanded(
            flex: 5,
            child: Text(
              _isRawView ? 'Material' : 'Item',
              style: style,
            ),
          ),
          Expanded(
            flex: 3,
            child: Text(
              narrow ? 'Qty' : 'Available Qty',
              style: style,
              textAlign: TextAlign.right,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          if (showValue)
            Expanded(
              flex: 3,
              child: Text(
                narrow ? 'Value' : 'Stock Value',
                style: style,
                textAlign: TextAlign.right,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          SizedBox(
            width: 52,
            child: Text('Ledger', style: style, textAlign: TextAlign.right),
          ),
        ],
      ),
    );
  }

  Widget _tableRow(
    BuildContext context,
    Map<String, dynamic> item, {
    required bool zebra,
    required bool showValue,
  }) {
    final name = '${item['name'] ?? '-'}';
    final qty = item['available_quantity'] ?? item['current_stock'] ?? 0;
    final unit = item['unit'] ?? '';
    final status = '${item['stock_status'] ?? ''}';
    final isLow = status == 'low' || status == 'low_stock';
    final isOut = status == 'out' || status == 'out_of_stock';
    final value = _parseMoney(
      item['stock_value'],
      context: 'item.stock_value id=${item['id']}',
    );

    final nameStyle = Theme.of(context).textTheme.bodySmall?.copyWith(
          color: AppColors.textPrimary,
          fontWeight: FontWeight.w500,
          height: 1.2,
        );
    final cellStyle = Theme.of(context).textTheme.bodySmall?.copyWith(
          color: isOut
              ? AppColors.error
              : isLow
                  ? AppColors.warning
                  : AppColors.textPrimary,
          fontFeatures: const [FontFeature.tabularFigures()],
        );

    return Material(
      color: zebra
          ? AppColors.background.withValues(alpha: 0.65)
          : AppColors.surface,
      child: InkWell(
        onTap: () => _openLedger(item),
        child: Container(
          constraints: const BoxConstraints(minHeight: 44),
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          decoration: const BoxDecoration(
            border: Border(
              bottom: BorderSide(color: AppColors.border, width: 0.5),
            ),
          ),
          child: Row(
            children: [
              Expanded(
                flex: 5,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      name,
                      style: nameStyle,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                    if (isLow || isOut)
                      Text(
                        isOut ? 'Out of stock' : 'Low stock',
                        style: Theme.of(context).textTheme.labelSmall?.copyWith(
                              color: isOut
                                  ? AppColors.error
                                  : AppColors.warning,
                            ),
                      ),
                  ],
                ),
              ),
              Expanded(
                flex: 3,
                child: Text(
                  _formatQty(qty, unit),
                  style: cellStyle,
                  textAlign: TextAlign.right,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              if (showValue)
                Expanded(
                  flex: 3,
                  child: Text(
                    _formatInr(value),
                    style: cellStyle?.copyWith(color: AppColors.textPrimary),
                    textAlign: TextAlign.right,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              SizedBox(
                width: 52,
                child: Align(
                  alignment: Alignment.centerRight,
                  child: IconButton(
                    visualDensity: VisualDensity.compact,
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(
                      minWidth: 36,
                      minHeight: 36,
                    ),
                    tooltip: 'Ledger',
                    icon: const Icon(Icons.receipt_long_outlined, size: 18),
                    color: AppColors.primary,
                    onPressed: () => _openLedger(item),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _totalBar(double? totalValue) {
    return Material(
      elevation: 6,
      color: AppColors.surface,
      child: SafeArea(
        top: false,
        child: Container(
          width: double.infinity,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          decoration: const BoxDecoration(
            border: Border(top: BorderSide(color: AppColors.border)),
          ),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  _totalBarLabel(),
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                        color: AppColors.textPrimary,
                      ),
                ),
              ),
              Text(
                _formatInr(totalValue),
                style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: AppColors.primary,
                      fontFeatures: const [FontFeature.tabularFigures()],
                    ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _LedgerDateRangeDialog extends StatefulWidget {
  const _LedgerDateRangeDialog({
    required this.initialFrom,
    required this.initialTo,
    required this.itemLabel,
  });

  final DateTime initialFrom;
  final DateTime initialTo;
  final String itemLabel;

  @override
  State<_LedgerDateRangeDialog> createState() => _LedgerDateRangeDialogState();
}

class _LedgerDateRangeDialogState extends State<_LedgerDateRangeDialog> {
  late DateTime _from;
  late DateTime _to;
  String? _error;

  @override
  void initState() {
    super.initState();
    _from = widget.initialFrom;
    _to = widget.initialTo;
  }

  String _label(DateTime d) => DateFormat('dd-MM-yyyy').format(d);

  Future<void> _pickFrom() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _from,
      firstDate: DateTime(2020),
      lastDate: DateTime(2100),
    );
    if (picked == null) return;
    setState(() {
      _from = DateTime(picked.year, picked.month, picked.day);
      _error = null;
    });
  }

  Future<void> _pickTo() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _to,
      firstDate: DateTime(2020),
      lastDate: DateTime(2100),
    );
    if (picked == null) return;
    setState(() {
      _to = DateTime(picked.year, picked.month, picked.day);
      _error = null;
    });
  }

  void _submit() {
    if (_from.isAfter(_to)) {
      setState(() => _error = 'From Date cannot be after To Date.');
      return;
    }
    Navigator.of(context).pop((from: _from, to: _to));
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Item Stock Ledger'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            widget.itemLabel,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w600,
                ),
          ),
          const SizedBox(height: AppSpacing.md),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('From'),
            subtitle: Text(_label(_from)),
            trailing: const Icon(Icons.calendar_today_outlined),
            onTap: _pickFrom,
          ),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('To'),
            subtitle: Text(_label(_to)),
            trailing: const Icon(Icons.calendar_today_outlined),
            onTap: _pickTo,
          ),
          if (_error != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(
              _error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: _submit,
          child: const Text('Open PDF'),
        ),
      ],
    );
  }
}
