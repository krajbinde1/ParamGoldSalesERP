import 'dart:developer' as developer;
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_pdfview/flutter_pdfview.dart';
import 'package:path_provider/path_provider.dart';

import '../design/app_colors.dart';
import '../design/app_spacing.dart';
import '../widgets/design/pg_empty_state.dart';

/// Opens an Admin-uploaded supporting document via authenticated API download.
Future<void> openSecureDocument(
  BuildContext context, {
  required Dio dio,
  required String title,
  String? mimeType,
  String? viewPath,
  String? viewUrl,
  int? documentId,
  int? paymentRequestId,
}) async {
  final resolved = resolveSecureDocumentDownloadTarget(
    viewPath: viewPath,
    viewUrl: viewUrl,
  );
  if (resolved == null || resolved.isEmpty) {
    if (!context.mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Document is not available.')),
    );
    return;
  }

  await Navigator.of(context).push(
    MaterialPageRoute<void>(
      builder: (_) => SecureDocumentViewerScreen(
        dio: dio,
        downloadTarget: resolved,
        title: title.trim().isEmpty ? 'Document' : title.trim(),
        mimeType: mimeType,
        documentId: documentId,
        paymentRequestId: paymentRequestId,
      ),
    ),
  );
}

/// Resolves a Dio-relative path that joins correctly with `baseUrl` ending in `/api`.
///
/// Dio 5 concatenates `baseUrl + path` without inserting `/`. Paths must therefore
/// start with `/` (e.g. `/director/...`) so the result is `…/api/director/...`,
/// not the broken `…/apidirector/...`.
@visibleForTesting
String? resolveSecureDocumentDownloadTarget({
  String? viewPath,
  String? viewUrl,
}) {
  final path = (viewPath ?? '').trim();
  if (path.isNotEmpty) {
    return _normalizeRelativeApiPath(path);
  }

  final url = (viewUrl ?? '').trim();
  if (url.isEmpty) return null;

  if (url.startsWith('http://') || url.startsWith('https://')) {
    const marker = '/api/';
    final idx = url.indexOf(marker);
    if (idx >= 0) {
      return _normalizeRelativeApiPath(url.substring(idx + marker.length));
    }
    // Absolute URL outside /api — return as-is for Dio (won't use baseUrl).
    return url;
  }

  return _normalizeRelativeApiPath(url);
}

String _normalizeRelativeApiPath(String raw) {
  var path = raw.trim();
  if (path.startsWith('/api/')) {
    path = path.substring(4); // keep leading /
  } else if (path.startsWith('api/')) {
    path = '/${path.substring(4)}';
  }
  if (!path.startsWith('/')) {
    path = '/$path';
  }
  return path;
}

class SecureDocumentViewerScreen extends StatefulWidget {
  const SecureDocumentViewerScreen({
    super.key,
    required this.dio,
    required this.downloadTarget,
    required this.title,
    this.mimeType,
    this.documentId,
    this.paymentRequestId,
  });

  final Dio dio;
  final String downloadTarget;
  final String title;
  final String? mimeType;
  final int? documentId;
  final int? paymentRequestId;

  @override
  State<SecureDocumentViewerScreen> createState() =>
      _SecureDocumentViewerScreenState();
}

class _SecureDocumentViewerScreenState extends State<SecureDocumentViewerScreen> {
  bool _loading = true;
  bool _failed = false;
  String? _localPath;
  late final bool _isPdf;
  late final String _extension;

  @override
  void initState() {
    super.initState();
    final lowerMime = (widget.mimeType ?? '').toLowerCase();
    final lowerTitle = widget.title.toLowerCase();
    _isPdf = lowerMime.contains('pdf') || lowerTitle.endsWith('.pdf');
    final isPng = lowerMime.contains('png') || lowerTitle.endsWith('.png');
    _extension = _isPdf ? 'pdf' : (isPng ? 'png' : 'jpg');
    _download();
  }

  Future<void> _download() async {
    setState(() {
      _loading = true;
      _failed = false;
      _localPath = null;
    });

    try {
      final dir = await getTemporaryDirectory();
      final path =
          '${dir.path}/payment_doc_${widget.documentId ?? DateTime.now().millisecondsSinceEpoch}.$_extension';

      if (kDebugMode) {
        developer.log(
          'PARAMGOLD_DOC_FETCH '
          'pr=${widget.paymentRequestId} doc=${widget.documentId} '
          'target=${widget.downloadTarget} mime=${widget.mimeType}',
          name: 'SecureDocument',
        );
      }

      final response = await widget.dio.download(
        widget.downloadTarget,
        path,
        options: Options(
          responseType: ResponseType.bytes,
          followRedirects: false,
          receiveTimeout: const Duration(seconds: 60),
          headers: const {
            'Accept': '*/*',
          },
          validateStatus: (status) =>
              status != null && status >= 200 && status < 300,
        ),
      );

      final file = File(path);
      final length = await file.exists() ? await file.length() : 0;
      if (length <= 0) {
        throw StateError('Empty document');
      }

      if (kDebugMode) {
        developer.log(
          'PARAMGOLD_DOC_OK '
          'status=${response.statusCode} '
          'contentType=${response.headers.value('content-type')} '
          'bytes=$length',
          name: 'SecureDocument',
        );
      }

      if (!mounted) return;
      setState(() {
        _localPath = path;
        _loading = false;
        _failed = false;
      });
    } catch (e) {
      if (kDebugMode) {
        final status = e is DioException ? e.response?.statusCode : null;
        final contentType = e is DioException
            ? e.response?.headers.value('content-type')
            : null;
        developer.log(
          'PARAMGOLD_DOC_FAIL '
          'pr=${widget.paymentRequestId} doc=${widget.documentId} '
          'status=$status contentType=$contentType error=${e.runtimeType}',
          name: 'SecureDocument',
        );
      }
      if (!mounted) return;
      setState(() {
        _loading = false;
        _failed = true;
        _localPath = null;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final isImage = !_isPdf;

    return Scaffold(
      backgroundColor: isImage ? Colors.black : Colors.white,
      appBar: AppBar(
        backgroundColor: isImage ? Colors.black : null,
        foregroundColor: isImage ? Colors.white : null,
        title: Text(
          widget.title,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
      ),
      body: _buildBody(isImage: isImage),
    );
  }

  Widget _buildBody({required bool isImage}) {
    if (_loading) {
      return Center(
        child: CircularProgressIndicator(
          color: isImage ? Colors.white : AppColors.primary,
        ),
      );
    }

    if (_failed || _localPath == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.xl),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.cloud_off_outlined,
                size: 44,
                color: isImage ? Colors.white70 : AppColors.error,
              ),
              const SizedBox(height: 12),
              Text(
                'Unable to open document',
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                  color: isImage ? Colors.white : AppColors.textPrimary,
                ),
              ),
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: _download,
                icon: const Icon(Icons.refresh),
                label: const Text('Retry'),
              ),
            ],
          ),
        ),
      );
    }

    if (_isPdf) {
      return PDFView(
        filePath: _localPath!,
        enableSwipe: true,
        swipeHorizontal: false,
        autoSpacing: true,
        pageFling: true,
      );
    }

    return Center(
      child: InteractiveViewer(
        minScale: 0.8,
        maxScale: 4,
        child: Image.file(
          File(_localPath!),
          fit: BoxFit.contain,
          errorBuilder: (_, error, stackTrace) => const Padding(
            padding: EdgeInsets.all(AppSpacing.screenPadding),
            child: PgErrorState(message: 'Unable to open document'),
          ),
        ),
      ),
    );
  }
}

String formatDocumentBytes(int? bytes) {
  final value = bytes ?? 0;
  if (value < 1024) return '$value B';
  if (value < 1048576) {
    return '${(value / 1024).toStringAsFixed(1)} KB';
  }
  return '${(value / 1048576).toStringAsFixed(1)} MB';
}

String documentTypeLabel({required String fileName, String? mimeType}) {
  final lower = '${mimeType ?? ''} $fileName'.toLowerCase();
  if (lower.contains('pdf')) return 'PDF';
  if (lower.contains('png')) return 'PNG';
  if (lower.contains('jpeg') || lower.contains('jpg')) return 'JPG';
  return 'File';
}

IconData documentIconFor({required String fileName, String? mimeType}) {
  final lower = '${mimeType ?? ''} $fileName'.toLowerCase();
  if (lower.contains('pdf')) return Icons.picture_as_pdf_outlined;
  return Icons.image_outlined;
}

Color documentIconColorFor({required String fileName, String? mimeType}) {
  final lower = '${mimeType ?? ''} $fileName'.toLowerCase();
  if (lower.contains('pdf')) return AppColors.error;
  return AppColors.primary;
}
