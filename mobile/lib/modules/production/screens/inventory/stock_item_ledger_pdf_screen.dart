import 'dart:async';
import 'dart:developer' as developer;
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_pdfview/flutter_pdfview.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../../../../core/api/api_errors.dart';
import '../../api/inventory_production_api.dart';

enum _LedgerPdfPhase { preparing, viewing, error }

/// Downloads Item Stock Ledger PDF with Bearer auth, validates bytes, then
/// opens a local [PDFView]. Never passes a protected remote URL to the viewer.
class StockItemLedgerPdfScreen extends StatefulWidget {
  const StockItemLedgerPdfScreen({
    super.key,
    required this.api,
    required this.itemType,
    required this.itemId,
    required this.itemCode,
    required this.fromYmd,
    required this.toYmd,
    this.title = 'Item Stock Ledger',
  });

  final InventoryProductionApi api;
  final String itemType;
  final int itemId;
  final String itemCode;
  final String fromYmd;
  final String toYmd;
  final String title;

  @override
  State<StockItemLedgerPdfScreen> createState() =>
      _StockItemLedgerPdfScreenState();
}

class _StockItemLedgerPdfScreenState extends State<StockItemLedgerPdfScreen> {
  static const _logName = 'ItemStockLedgerPdf';

  _LedgerPdfPhase _phase = _LedgerPdfPhase.preparing;
  String? _errorMessage;
  String? _localPath;
  String _filename = 'Item_Stock_Ledger.pdf';
  bool _documentReady = false;
  Timer? _loadWatchdog;

  @override
  void initState() {
    super.initState();
    unawaited(_preparePdf());
  }

  @override
  void dispose() {
    _loadWatchdog?.cancel();
    super.dispose();
  }

  void _log(String message) {
    if (kDebugMode) {
      developer.log(message, name: _logName);
      debugPrint('[$_logName] $message');
    }
  }

  Future<void> _preparePdf() async {
    _loadWatchdog?.cancel();
    setState(() {
      _phase = _LedgerPdfPhase.preparing;
      _errorMessage = null;
      _documentReady = false;
      _localPath = null;
    });

    try {
      final result = await widget.api.downloadItemLedgerPdf(
        itemType: widget.itemType,
        itemId: widget.itemId,
        from: widget.fromYmd,
        to: widget.toYmd,
      );

      final bytes = Uint8List.fromList(result.bytes);
      _log(
        'Downloaded bytes=${bytes.length} filename=${result.filename} '
        'header=${_headerPreview(bytes)}',
      );

      if (bytes.isEmpty || !_isPdfHeader(bytes)) {
        throw StateError(
          'Server did not return a valid PDF '
          '(size=${bytes.length}, header=${_headerPreview(bytes)}).',
        );
      }

      final safeName = _safeFilename(result.filename);
      final dir = await getTemporaryDirectory();
      final stamp = DateTime.now().millisecondsSinceEpoch;
      final file = File('${dir.path}/ledger_${widget.itemId}_${stamp}_$safeName');
      await file.writeAsBytes(bytes, flush: true);

      if (!await file.exists()) {
        throw StateError('Failed to write ledger PDF to app temp storage.');
      }
      final localSize = await file.length();
      if (localSize <= 0 || localSize != bytes.length) {
        throw StateError(
          'Local PDF size mismatch (expected ${bytes.length}, got $localSize).',
        );
      }

      _log('Saved local path=${file.path} size=$localSize');

      if (!mounted) return;
      setState(() {
        _localPath = file.path;
        _filename = safeName;
        _phase = _LedgerPdfPhase.viewing;
      });

      _loadWatchdog = Timer(const Duration(seconds: 20), () {
        if (!mounted || _documentReady || _phase != _LedgerPdfPhase.viewing) {
          return;
        }
        _log('Watchdog: document did not load within 20s');
        setState(() {
          _phase = _LedgerPdfPhase.error;
          _errorMessage =
              'PDF viewer timed out while loading the ledger. Please retry.';
        });
      });
    } catch (e, st) {
      _log('Prepare failed: $e\n$st');
      if (!mounted) return;
      setState(() {
        _phase = _LedgerPdfPhase.error;
        _errorMessage = _friendlyError(e);
      });
    }
  }

  String _friendlyError(Object e) {
    if (e is DioException) return errorMessage(e);
    if (e is ApiForbiddenException) return e.message;
    final raw = errorMessage(e);
    return raw.isEmpty ? 'Unable to prepare Item Stock Ledger PDF.' : raw;
  }

  String _safeFilename(String name) {
    final cleaned = name.replaceAll(RegExp(r'[\\/:*?"<>|]'), '_').trim();
    if (cleaned.isNotEmpty && cleaned.toLowerCase().endsWith('.pdf')) {
      return cleaned;
    }
    return 'Item_Stock_Ledger_${widget.itemCode}_'
        '${widget.fromYmd}_to_${widget.toYmd}.pdf';
  }

  bool _isPdfHeader(List<int> bytes) {
    return bytes.length >= 4 &&
        bytes[0] == 0x25 &&
        bytes[1] == 0x50 &&
        bytes[2] == 0x44 &&
        bytes[3] == 0x46;
  }

  String _headerPreview(List<int> bytes) {
    final n = bytes.length < 8 ? bytes.length : 8;
    if (n == 0) return '(empty)';
    final chars = String.fromCharCodes(
      bytes.take(n).map((b) => (b >= 32 && b < 127) ? b : 0x2E),
    );
    return chars;
  }

  Future<void> _share() async {
    final path = _localPath;
    if (path == null || !_documentReady) return;
    final file = File(path);
    if (!await file.exists() || await file.length() <= 0) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('PDF file is no longer available.')),
      );
      return;
    }
    await SharePlus.instance.share(
      ShareParams(
        files: [XFile(path, mimeType: 'application/pdf')],
        subject: _filename,
        text: widget.title,
      ),
    );
  }

  void _onRender(int? pages) {
    _log('onRender pages=$pages');
    _loadWatchdog?.cancel();
    if (!mounted) return;
    if (pages == null || pages <= 0) {
      setState(() {
        _documentReady = false;
        _phase = _LedgerPdfPhase.error;
        _errorMessage = 'Failed to open Item Stock Ledger PDF in viewer.';
      });
      return;
    }
    setState(() => _documentReady = true);
  }

  void _onViewerError(dynamic error) {
    _log('onError: $error');
    _loadWatchdog?.cancel();
    if (!mounted) return;
    setState(() {
      _documentReady = false;
      _phase = _LedgerPdfPhase.error;
      _errorMessage = 'PDF viewer error: ${_safeViewerError(error)}';
    });
  }

  @override
  Widget build(BuildContext context) {
    final canShare =
        _phase == _LedgerPdfPhase.viewing && _documentReady && _localPath != null;

    return Scaffold(
      appBar: AppBar(
        title: Text(widget.title),
        actions: [
          IconButton(
            tooltip: canShare ? 'Share / save / print' : 'Share unavailable',
            onPressed: canShare ? _share : null,
            icon: const Icon(Icons.share_outlined),
          ),
        ],
      ),
      body: switch (_phase) {
        _LedgerPdfPhase.preparing => const _PreparingBody(),
        _LedgerPdfPhase.error => _ErrorBody(
            message:
                _errorMessage ?? 'Unable to prepare Item Stock Ledger PDF.',
            onRetry: _preparePdf,
            onBack: () => Navigator.of(context).maybePop(),
          ),
        _LedgerPdfPhase.viewing => _buildViewer(),
      },
    );
  }

  Widget _buildViewer() {
    final path = _localPath;
    if (path == null) {
      return _ErrorBody(
        message: 'Ledger PDF is missing from local storage.',
        onRetry: _preparePdf,
        onBack: () => Navigator.of(context).maybePop(),
      );
    }

    return Stack(
      children: [
        PDFView(
          key: ValueKey(path),
          filePath: path,
          enableSwipe: true,
          swipeHorizontal: false,
          autoSpacing: true,
          pageFling: true,
          onRender: _onRender,
          onError: _onViewerError,
          onPageError: (page, error) {
            _log('onPageError page=$page error=$error');
          },
        ),
        if (!_documentReady)
          const ColoredBox(
            color: Colors.white70,
            child: _PreparingBody(label: 'Opening Item Stock Ledger…'),
          ),
      ],
    );
  }

  String _safeViewerError(Object error) {
    final text = '$error';
    if (text.length > 180) return '${text.substring(0, 180)}…';
    return text;
  }
}

class _PreparingBody extends StatelessWidget {
  const _PreparingBody({this.label = 'Preparing Item Stock Ledger…'});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const CircularProgressIndicator(),
            const SizedBox(height: 16),
            Text(
              label,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleMedium,
            ),
          ],
        ),
      ),
    );
  }
}

class _ErrorBody extends StatelessWidget {
  const _ErrorBody({
    required this.message,
    required this.onRetry,
    required this.onBack,
  });

  final String message;
  final VoidCallback onRetry;
  final VoidCallback onBack;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              Icons.cloud_off_rounded,
              size: 48,
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
            const SizedBox(height: 16),
            Text(
              message,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 16),
            Wrap(
              spacing: 12,
              alignment: WrapAlignment.center,
              children: [
                OutlinedButton(onPressed: onBack, child: const Text('Back')),
                FilledButton.tonal(
                  onPressed: onRetry,
                  child: const Text('Retry'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
