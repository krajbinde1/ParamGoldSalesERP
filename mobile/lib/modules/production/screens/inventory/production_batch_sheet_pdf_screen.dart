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

enum _BatchSheetPdfPhase { preparing, viewing, error }

/// Downloads the Admin Production Batch Sheet PDF and opens/shares it on device.
class ProductionBatchSheetPdfScreen extends StatefulWidget {
  const ProductionBatchSheetPdfScreen({
    super.key,
    required this.api,
    required this.batchId,
    this.title = 'Production Batch Sheet',
  });

  final InventoryProductionApi api;
  final int batchId;
  final String title;

  @override
  State<ProductionBatchSheetPdfScreen> createState() =>
      _ProductionBatchSheetPdfScreenState();
}

class _ProductionBatchSheetPdfScreenState
    extends State<ProductionBatchSheetPdfScreen> {
  static const _logName = 'ProductionBatchSheetPdf';

  _BatchSheetPdfPhase _phase = _BatchSheetPdfPhase.preparing;
  String? _errorMessage;
  String? _localPath;
  String _filename = 'Production_Batch_Sheet.pdf';
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
      _phase = _BatchSheetPdfPhase.preparing;
      _errorMessage = null;
      _documentReady = false;
      _localPath = null;
    });

    try {
      final result = await widget.api.downloadBatchSheetPdf(widget.batchId);
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
      final file = File('${dir.path}/batch_sheet_${stamp}_$safeName');
      await file.writeAsBytes(bytes, flush: true);

      if (!await file.exists()) {
        throw StateError('Failed to write batch sheet PDF to app temp storage.');
      }
      final localSize = await file.length();
      if (localSize <= 0 || localSize != bytes.length) {
        throw StateError(
          'Local PDF size mismatch (expected ${bytes.length}, got $localSize).',
        );
      }

      if (!mounted) return;
      setState(() {
        _localPath = file.path;
        _filename = safeName;
        _phase = _BatchSheetPdfPhase.viewing;
      });

      _loadWatchdog = Timer(const Duration(seconds: 20), () {
        if (!mounted ||
            _documentReady ||
            _phase != _BatchSheetPdfPhase.viewing) {
          return;
        }
        setState(() {
          _phase = _BatchSheetPdfPhase.error;
          _errorMessage =
              'PDF viewer timed out while loading the batch sheet. Please retry.';
        });
      });
    } catch (e, st) {
      _log('Prepare failed: $e\n$st');
      if (!mounted) return;
      setState(() {
        _phase = _BatchSheetPdfPhase.error;
        _errorMessage = _friendlyError(e);
      });
    }
  }

  String _friendlyError(Object e) {
    if (e is DioException) return errorMessage(e);
    if (e is ApiForbiddenException) return e.message;
    final raw = errorMessage(e);
    return raw.isEmpty ? 'Unable to prepare Production Batch Sheet PDF.' : raw;
  }

  String _safeFilename(String name) {
    final cleaned = name.replaceAll(RegExp(r'[\\/:*?"<>|]'), '_').trim();
    if (cleaned.isNotEmpty && cleaned.toLowerCase().endsWith('.pdf')) {
      return cleaned;
    }
    return 'Production_Batch_Sheet_${widget.batchId}.pdf';
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
    _loadWatchdog?.cancel();
    if (!mounted) return;
    if (pages == null || pages <= 0) {
      setState(() {
        _documentReady = false;
        _phase = _BatchSheetPdfPhase.error;
        _errorMessage = 'Failed to open Production Batch Sheet PDF in viewer.';
      });
      return;
    }
    setState(() => _documentReady = true);
  }

  void _onViewerError(dynamic error) {
    _loadWatchdog?.cancel();
    if (!mounted) return;
    setState(() {
      _documentReady = false;
      _phase = _BatchSheetPdfPhase.error;
      _errorMessage = 'PDF viewer error: $error';
    });
  }

  @override
  Widget build(BuildContext context) {
    final canShare = _phase == _BatchSheetPdfPhase.viewing &&
        _documentReady &&
        _localPath != null;

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
        _BatchSheetPdfPhase.preparing => const Center(
            child: Padding(
              padding: EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  CircularProgressIndicator(),
                  SizedBox(height: 16),
                  Text('Preparing Production Batch Sheet…'),
                ],
              ),
            ),
          ),
        _BatchSheetPdfPhase.error => Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    _errorMessage ??
                        'Unable to prepare Production Batch Sheet PDF.',
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 16),
                  Wrap(
                    spacing: 12,
                    alignment: WrapAlignment.center,
                    children: [
                      OutlinedButton(
                        onPressed: () => Navigator.of(context).maybePop(),
                        child: const Text('Cancel'),
                      ),
                      FilledButton.tonal(
                        onPressed: _preparePdf,
                        child: const Text('Retry'),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        _BatchSheetPdfPhase.viewing => _buildViewer(),
      },
    );
  }

  Widget _buildViewer() {
    final path = _localPath;
    if (path == null) {
      return const Center(child: Text('Batch sheet PDF is missing.'));
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
        ),
        if (!_documentReady)
          const ColoredBox(
            color: Colors.white70,
            child: Center(child: CircularProgressIndicator()),
          ),
      ],
    );
  }
}
