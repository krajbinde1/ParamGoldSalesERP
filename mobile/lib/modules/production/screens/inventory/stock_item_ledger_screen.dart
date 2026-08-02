import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:webview_flutter/webview_flutter.dart';

import '../../../../core/api/api_client.dart';
import '../../../../core/api/api_errors.dart';
import '../../../../core/design/app_spacing.dart';
import '../../../../core/storage/session_store.dart';
import '../../../../core/widgets/design/pg_empty_state.dart';
import '../../../../core/widgets/role_shell_widgets.dart';
import '../../../auth/providers/auth_controller.dart';
import '../../api/inventory_production_api.dart';

/// Full-width Item Stock Ledger matching Filament web ledger exactly.
/// Nested vertical + horizontal scroll; does not squash columns.
class StockItemLedgerScreen extends StatefulWidget {
  const StockItemLedgerScreen({
    super.key,
    required this.auth,
    required this.itemType,
    required this.itemId,
  });

  final AuthController auth;
  final String itemType;
  final int itemId;

  @override
  State<StockItemLedgerScreen> createState() => _StockItemLedgerScreenState();
}

class _StockItemLedgerScreenState extends State<StockItemLedgerScreen> {
  static const _colDate = 95.0;
  static const _colParticulars = 380.0;
  static const _colVoucher = 150.0;
  static const _colInQty = 125.0;
  static const _colInVal = 125.0;
  static const _colOutQty = 135.0;
  static const _colOutVal = 125.0;
  static const _colCloseQty = 135.0;
  static const _colAvgRate = 175.0;
  static const _colCloseVal = 125.0;

  late DateTime _from;
  late DateTime _to;
  DateTime? _appliedFrom;
  DateTime? _appliedTo;
  Future<Map<String, dynamic>>? _future;
  bool _loading = false;
  bool _exporting = false;
  bool _printing = false;
  bool _fullScreen = false;
  int _requestSeq = 0;

  InventoryProductionApi get _api => InventoryProductionApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _from = DateTime(now.year, now.month, 1);
    _to = DateTime(now.year, now.month, now.day);
    _appliedFrom = _from;
    _appliedTo = _to;
    _future = _load();
    SystemChrome.setPreferredOrientations(const [
      DeviceOrientation.portraitUp,
      DeviceOrientation.landscapeLeft,
      DeviceOrientation.landscapeRight,
    ]);
  }

  @override
  void dispose() {
    SystemChrome.setPreferredOrientations(DeviceOrientation.values);
    SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
    super.dispose();
  }

  String _ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<Map<String, dynamic>> _load() {
    final from = _appliedFrom ?? _from;
    final to = _appliedTo ?? _to;
    return _api.itemLedger(
      itemType: widget.itemType,
      itemId: widget.itemId,
      from: _ymd(from),
      to: _ymd(to),
      perPage: 200,
    );
  }

  Future<void> _reload() async {
    if (_loading) return;
    final seq = ++_requestSeq;
    setState(() {
      _loading = true;
      _future = _load();
    });
    try {
      await _future;
    } finally {
      if (mounted && seq == _requestSeq) {
        setState(() => _loading = false);
      }
    }
  }

  Future<void> _applyFilters() async {
    if (_from.isAfter(_to)) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('From Date cannot be after To Date.')),
      );
      return;
    }
    setState(() {
      _appliedFrom = _from;
      _appliedTo = _to;
    });
    await _reload();
  }

  Future<void> _pickFrom() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _from.isAfter(_to) ? _to : _from,
      firstDate: DateTime(2020),
      lastDate: DateTime.now().add(const Duration(days: 365)),
    );
    if (picked != null) setState(() => _from = picked);
  }

  Future<void> _pickTo() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _to.isBefore(_from) ? _from : _to,
      firstDate: DateTime(2020),
      lastDate: DateTime.now().add(const Duration(days: 365)),
    );
    if (picked != null) setState(() => _to = picked);
  }

  Future<void> _toggleFullScreen() async {
    final next = !_fullScreen;
    setState(() => _fullScreen = next);
    if (next) {
      await SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersiveSticky);
      await SystemChrome.setPreferredOrientations(const [
        DeviceOrientation.landscapeLeft,
        DeviceOrientation.landscapeRight,
        DeviceOrientation.portraitUp,
      ]);
    } else {
      await SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
    }
  }

  void _backToReport() {
    if (context.canPop()) {
      context.pop();
    } else {
      context.go('/production/stock-report');
    }
  }

  Future<void> _exportExcel() async {
    if (_exporting) return;
    setState(() => _exporting = true);
    try {
      final from = _appliedFrom ?? _from;
      final to = _appliedTo ?? _to;
      final bytes = await _api.exportItemLedgerExcel(
        itemType: widget.itemType,
        itemId: widget.itemId,
        from: _ymd(from),
        to: _ymd(to),
      );
      if (bytes.isEmpty) {
        throw StateError('Empty Excel response');
      }
      final dir = await getTemporaryDirectory();
      final file = File(
        '${dir.path}/Stock_Ledger_${widget.itemId}_${_ymd(from)}_to_${_ymd(to)}.xlsx',
      );
      await file.writeAsBytes(bytes, flush: true);
      await OpenFilex.open(file.path);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Excel saved: ${file.path}')),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(e))),
      );
    } finally {
      if (mounted) setState(() => _exporting = false);
    }
  }

  Future<void> _printLedger() async {
    if (_printing) return;
    setState(() => _printing = true);
    try {
      final from = _appliedFrom ?? _from;
      final to = _appliedTo ?? _to;
      final html = await _api.itemLedgerPrintHtml(
        itemType: widget.itemType,
        itemId: widget.itemId,
        from: _ymd(from),
        to: _ymd(to),
      );
      if (!mounted) return;
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => _LedgerPrintWebView(html: html),
        ),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(e))),
      );
    } finally {
      if (mounted) setState(() => _printing = false);
    }
  }

  void _openReference(Map<String, dynamic> row) {
    final route = '${row['mobile_route'] ?? ''}'.trim();
    final voucher = '${row['voucher_display'] ?? row['voucher_no'] ?? ''}'.trim();
    if (route.isNotEmpty) {
      context.push(route);
      return;
    }
    if (voucher.isEmpty || voucher == '—') return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          'Detail screen is not available on mobile for $voucher.',
        ),
      ),
    );
  }

  List<Map<String, dynamic>> _displayRows(Map<String, dynamic> data) {
    final raw = data['display_rows'] as List?;
    if (raw != null && raw.isNotEmpty) {
      return raw.map((e) => Map<String, dynamic>.from(e as Map)).toList();
    }

    // Fallback if older API without display_rows
    final opening =
        Map<String, dynamic>.from(data['opening_balance'] as Map? ?? {});
    final closing =
        Map<String, dynamic>.from(data['closing_balance'] as Map? ?? {});
    final txns = (data['transactions'] as List? ?? data['rows'] as List? ?? [])
        .map((e) => Map<String, dynamic>.from(e as Map))
        .toList();

    return [
      {
        'row_type': 'opening_balance',
        'date_display': opening['date'] ?? '',
        'particulars_display': 'Opening Balance',
        'voucher_display': '—',
        'inward_qty_display': '—',
        'inward_value_display': '—',
        'outward_qty_display': '—',
        'outward_value_display': '—',
        'closing_qty_display': '${opening['closing_quantity'] ?? ''}',
        'average_purchase_rate_display':
            '${opening['average_purchase_rate'] ?? ''}',
        'closing_value_display': '${opening['closing_value'] ?? ''}',
        'mobile_route': null,
      },
      ...txns.map(
        (r) => {
          'row_type': 'transaction',
          'date_display': r['date'] ?? '',
          'particulars_display': r['particulars'] ?? '',
          'voucher_display':
              r['voucher_reference_number'] ?? r['voucher_no'] ?? '—',
          'inward_qty_display': '${r['inward_quantity'] ?? '—'}',
          'inward_value_display': '${r['inward_value'] ?? '—'}',
          'outward_qty_display': '${r['outward_quantity'] ?? '—'}',
          'outward_value_display': '${r['outward_value'] ?? '—'}',
          'closing_qty_display': '${r['closing_quantity'] ?? ''}',
          'average_purchase_rate_display':
              '${r['average_purchase_rate'] ?? r['closing_rate'] ?? ''}',
          'closing_value_display': '${r['closing_value'] ?? ''}',
          'mobile_route': r['mobile_route'],
        },
      ),
      {
        'row_type': 'closing_balance',
        'date_display': '',
        'particulars_display': 'Closing Balance',
        'voucher_display': '',
        'inward_qty_display': '${closing['total_inward_quantity'] ?? ''}',
        'inward_value_display': '${closing['total_inward_value'] ?? ''}',
        'outward_qty_display': '${closing['total_outward_quantity'] ?? ''}',
        'outward_value_display': '${closing['total_outward_value'] ?? ''}',
        'closing_qty_display': '${closing['closing_quantity'] ?? ''}',
        'average_purchase_rate_display':
            '${closing['average_purchase_rate'] ?? closing['closing_rate'] ?? ''}',
        'closing_value_display': '${closing['closing_value'] ?? ''}',
        'mobile_route': null,
      },
    ];
  }

  @override
  Widget build(BuildContext context) {
    if (widget.itemId <= 0 || widget.itemType.isEmpty) {
      return Scaffold(
        appBar: RoleAppBar(title: 'Item Stock Ledger', auth: widget.auth),
        body: const PgEmptyState(
          message: 'Open an item from Inventory Stock Report using Ledger.',
        ),
      );
    }

    return Scaffold(
      appBar: _fullScreen
          ? AppBar(
              title: const Text('Item Stock Ledger'),
              leading: IconButton(
                icon: const Icon(Icons.close_fullscreen),
                tooltip: 'Exit full screen',
                onPressed: _toggleFullScreen,
              ),
            )
          : RoleAppBar(title: 'Item Stock Ledger', auth: widget.auth),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting &&
              !snapshot.hasData) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return Padding(
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              child: PgErrorState(
                message: errorMessage(snapshot.error),
                onRetry: _reload,
              ),
            );
          }

          final data = snapshot.data!;
          final showCosts = data['can_view_costs'] == true ||
              widget.auth.permissions.canViewProductionCosts;
          final item = Map<String, dynamic>.from(
            data['item'] as Map? ?? data['header'] as Map? ?? {},
          );
          final header =
              Map<String, dynamic>.from(data['header'] as Map? ?? {});
          final name = '${item['name'] ?? header['item_name'] ?? '—'}'.trim();
          final code = '${item['code'] ?? header['item_code'] ?? '—'}'.trim();
          final unit = '${item['unit'] ?? header['unit'] ?? ''}'.trim();
          final warning = '${header['warning'] ?? ''}'.trim();
          final rows = _displayRows(data);
          final hasTxns = rows.any((r) => r['row_type'] == 'transaction');

          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (_loading) const LinearProgressIndicator(minHeight: 2),
              if (!_fullScreen) ...[
                _actionBar(),
                Padding(
                  padding: const EdgeInsets.fromLTRB(12, 4, 12, 0),
                  child: _itemHeading(
                    name: name,
                    code: code,
                    unit: unit,
                    warning: warning,
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(12, 8, 12, 4),
                  child: _dateFilters(),
                ),
              ] else
                Padding(
                  padding: const EdgeInsets.fromLTRB(8, 4, 8, 4),
                  child: Text(
                    '$name · Code : $code · Unit : ${unit.isEmpty ? '—' : unit}',
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ),
              Expanded(
                child: RefreshIndicator(
                  onRefresh: _reload,
                  child: SingleChildScrollView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    child: SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: Padding(
                        padding: const EdgeInsets.fromLTRB(8, 0, 8, 16),
                        child: _ledgerTable(
                          rows: rows,
                          showCosts: showCosts,
                          hasTxns: hasTxns,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _actionBar() {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.fromLTRB(8, 8, 8, 0),
      child: Row(
        children: [
          OutlinedButton.icon(
            onPressed: _backToReport,
            icon: const Icon(Icons.arrow_back, size: 18),
            label: const Text('Back to Stock Report'),
          ),
          const SizedBox(width: 8),
          FilledButton.tonalIcon(
            onPressed: _exporting ? null : _exportExcel,
            icon: _exporting
                ? const SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.download, size: 18),
            label: const Text('Export Excel'),
          ),
          const SizedBox(width: 8),
          OutlinedButton.icon(
            onPressed: _printing ? null : _printLedger,
            icon: _printing
                ? const SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.print, size: 18),
            label: const Text('Print'),
          ),
          const SizedBox(width: 8),
          OutlinedButton.icon(
            onPressed: _toggleFullScreen,
            icon: const Icon(Icons.fullscreen, size: 18),
            label: const Text('Full Screen'),
          ),
        ],
      ),
    );
  }

  Widget _itemHeading({
    required String name,
    required String code,
    required String unit,
    required String warning,
  }) {
    return Column(
      children: [
        Text(
          name,
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w700,
              ),
        ),
        Text(
          'Stock Ledger',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w600,
              ),
        ),
        Text(
          'Code : $code',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.bodySmall,
        ),
        Text(
          'Unit : ${unit.isEmpty ? '—' : unit}',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.bodySmall,
        ),
        if (warning.isNotEmpty)
          Tooltip(
            message: warning,
            child: Icon(
              Icons.warning_amber_rounded,
              size: 16,
              color: Colors.amber.shade800,
            ),
          ),
      ],
    );
  }

  Widget _dateFilters() {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        OutlinedButton(
          onPressed: _pickFrom,
          child: Text('From Date: ${_ymd(_from)}'),
        ),
        OutlinedButton(
          onPressed: _pickTo,
          child: Text('To Date: ${_ymd(_to)}'),
        ),
        FilledButton(
          onPressed: _loading ? null : _applyFilters,
          child: const Text('Apply Filter'),
        ),
      ],
    );
  }

  double get _tableWidthWithCosts =>
      _colDate +
      _colParticulars +
      _colVoucher +
      _colInQty +
      _colInVal +
      _colOutQty +
      _colOutVal +
      _colCloseQty +
      _colAvgRate +
      _colCloseVal;

  double get _tableWidthNoCosts =>
      _colDate +
      _colParticulars +
      _colVoucher +
      _colInQty +
      _colOutQty +
      _colCloseQty;

  Widget _ledgerTable({
    required List<Map<String, dynamic>> rows,
    required bool showCosts,
    required bool hasTxns,
  }) {
    final width = showCosts ? _tableWidthWithCosts : _tableWidthNoCosts;
    final border = TableBorder.all(color: const Color(0xFF333333), width: 1);
    final headerStyle = const TextStyle(
      fontWeight: FontWeight.w700,
      fontSize: 12,
      color: Color(0xFF111111),
    );
    final cellStyle = const TextStyle(
      fontSize: 12,
      color: Color(0xFF111111),
      fontFeatures: [FontFeature.tabularFigures()],
    );

    Map<int, TableColumnWidth> columnWidths() {
      var i = 0;
      final map = <int, TableColumnWidth>{
        i++: const FixedColumnWidth(_colDate),
        i++: const FixedColumnWidth(_colParticulars),
        i++: const FixedColumnWidth(_colVoucher),
        i++: const FixedColumnWidth(_colInQty),
      };
      if (showCosts) {
        map[i++] = const FixedColumnWidth(_colInVal);
      }
      map[i++] = const FixedColumnWidth(_colOutQty);
      if (showCosts) {
        map[i++] = const FixedColumnWidth(_colOutVal);
      }
      map[i++] = const FixedColumnWidth(_colCloseQty);
      if (showCosts) {
        map[i++] = const FixedColumnWidth(_colAvgRate);
        map[i++] = const FixedColumnWidth(_colCloseVal);
      }
      return map;
    }

    TableCell headerCell(String text, {bool num = false}) => TableCell(
          verticalAlignment: TableCellVerticalAlignment.middle,
          child: Container(
            color: const Color(0xFFF3F4F6),
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
            alignment: num ? Alignment.centerRight : Alignment.centerLeft,
            child: Text(text, style: headerStyle),
          ),
        );

    TableCell textCell(
      String text, {
      bool num = false,
      bool bold = false,
      bool link = false,
      VoidCallback? onTap,
      Color? background,
    }) {
      final style = cellStyle.copyWith(
        fontWeight: bold ? FontWeight.w700 : FontWeight.w400,
        color: link ? const Color(0xFF1D4ED8) : cellStyle.color,
        decoration: link ? TextDecoration.underline : null,
        decorationStyle: link ? TextDecorationStyle.dotted : null,
      );
      final child = Padding(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
        child: Align(
          alignment: num ? Alignment.centerRight : Alignment.centerLeft,
          child: Text(text, style: style),
        ),
      );
      return TableCell(
        verticalAlignment: TableCellVerticalAlignment.middle,
        child: Container(
          color: background,
          child: onTap != null
              ? InkWell(onTap: onTap, child: child)
              : child,
        ),
      );
    }

    final header = TableRow(
      children: [
        headerCell('Date'),
        headerCell('Particulars'),
        headerCell('Voucher / Ref. No.'),
        headerCell('Inward Quantity', num: true),
        if (showCosts) headerCell('Inward Value', num: true),
        headerCell('Outward Quantity', num: true),
        if (showCosts) headerCell('Outward Value', num: true),
        headerCell('Closing Quantity', num: true),
        if (showCosts) headerCell('Average Purchase Rate', num: true),
        if (showCosts) headerCell('Closing Value', num: true),
      ],
    );

    final bodyRows = <TableRow>[];
    final colCount = showCosts ? 10 : 6;
    for (final row in rows) {
      final type = '${row['row_type']}';
      if (type == 'transaction' && !hasTxns) continue;
      bodyRows.add(_dataRow(
        row: row,
        showCosts: showCosts,
        textCell: textCell,
      ));
      if ((type == 'opening_balance' || type == 'opening') && !hasTxns) {
        bodyRows.add(
          TableRow(
            children: [
              for (var c = 0; c < colCount; c++)
                TableCell(
                  child: c == 1
                      ? const Padding(
                          padding: EdgeInsets.symmetric(
                            horizontal: 6,
                            vertical: 10,
                          ),
                          child: Text(
                            'No transactions in the selected period.',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              fontSize: 12,
                              color: Color(0xFF6B7280),
                            ),
                          ),
                        )
                      : const SizedBox(height: 36),
                ),
            ],
          ),
        );
      }
    }

    return SizedBox(
      width: width,
      child: Table(
        border: border,
        columnWidths: columnWidths(),
        defaultVerticalAlignment: TableCellVerticalAlignment.middle,
        children: [header, ...bodyRows],
      ),
    );
  }

  TableRow _dataRow({
    required Map<String, dynamic> row,
    required bool showCosts,
    required TableCell Function(
      String text, {
      bool num,
      bool bold,
      bool link,
      VoidCallback? onTap,
      Color? background,
    }) textCell,
  }) {
    final type = '${row['row_type']}';
    final isOpening = type == 'opening_balance' || type == 'opening';
    final isClosing = type == 'closing_balance' || type == 'closing';
    final bold = isOpening || isClosing;
    final bg = isOpening ? const Color(0xFFF8FAFC) : null;
    final voucher = '${row['voucher_display'] ?? ''}'.trim();
    final route = '${row['mobile_route'] ?? ''}'.trim();
    final voucherIsLink = !isOpening &&
        !isClosing &&
        voucher.isNotEmpty &&
        voucher != '—' &&
        route.isNotEmpty;

    return TableRow(
      children: [
        textCell('${row['date_display'] ?? ''}', bold: bold, background: bg),
        textCell(
          '${row['particulars_display'] ?? ''}',
          bold: bold,
          background: bg,
        ),
        textCell(
          voucher,
          bold: bold,
          link: voucherIsLink,
          onTap: voucherIsLink ? () => _openReference(row) : null,
          background: bg,
        ),
        textCell(
          '${row['inward_qty_display'] ?? ''}',
          num: true,
          bold: bold,
          background: bg,
        ),
        if (showCosts)
          textCell(
            '${row['inward_value_display'] ?? ''}',
            num: true,
            bold: bold,
            background: bg,
          ),
        textCell(
          '${row['outward_qty_display'] ?? ''}',
          num: true,
          bold: bold,
          background: bg,
        ),
        if (showCosts)
          textCell(
            '${row['outward_value_display'] ?? ''}',
            num: true,
            bold: bold,
            background: bg,
          ),
        textCell(
          '${row['closing_qty_display'] ?? ''}',
          num: true,
          bold: bold,
          background: bg,
        ),
        if (showCosts)
          textCell(
            '${row['average_purchase_rate_display'] ?? ''}',
            num: true,
            bold: bold,
            background: bg,
          ),
        if (showCosts)
          textCell(
            '${row['closing_value_display'] ?? ''}',
            num: true,
            bold: bold,
            background: bg,
          ),
      ],
    );
  }
}

class _LedgerPrintWebView extends StatefulWidget {
  const _LedgerPrintWebView({required this.html});

  final String html;

  @override
  State<_LedgerPrintWebView> createState() => _LedgerPrintWebViewState();
}

class _LedgerPrintWebViewState extends State<_LedgerPrintWebView> {
  late final WebViewController _controller;

  @override
  void initState() {
    super.initState();
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..loadHtmlString(widget.html);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Print — Item Stock Ledger'),
        actions: [
          IconButton(
            tooltip: 'Print',
            icon: const Icon(Icons.print),
            onPressed: () {
              _controller.runJavaScript('window.print();');
            },
          ),
        ],
      ),
      body: WebViewWidget(controller: _controller),
    );
  }
}
