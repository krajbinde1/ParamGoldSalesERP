import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:path_provider/path_provider.dart';

import '../design/app_colors.dart';
import '../design/app_spacing.dart';
import '../widgets/design/pg_empty_state.dart';
import 'bill_document.dart';

/// Opens a supporting document via an authenticated API URL.
Future<void> openSecureDocument(
  BuildContext context, {
  required Dio dio,
  required String url,
  required String title,
  String? mimeType,
}) async {
  if (url.trim().isEmpty) {
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Document is not available.')),
    );
    return;
  }

  showDialog<void>(
    context: context,
    barrierDismissible: false,
    builder: (_) => const Center(child: CircularProgressIndicator()),
  );

  try {
    final dir = await getTemporaryDirectory();
    final lowerMime = (mimeType ?? '').toLowerCase();
    final lowerUrl = url.toLowerCase();
    final lowerTitle = title.toLowerCase();
    final isPdf = lowerMime.contains('pdf') ||
        lowerUrl.contains('.pdf') ||
        lowerTitle.endsWith('.pdf');
    final isPng = lowerMime.contains('png') || lowerTitle.endsWith('.png');
    final extension = isPdf
        ? 'pdf'
        : (isPng ? 'png' : 'jpg');
    final path =
        '${dir.path}/payment_doc_${DateTime.now().millisecondsSinceEpoch}.$extension';

    await dio.download(url, path);

    if (!context.mounted) return;
    Navigator.of(context, rootNavigator: true).pop();

    if (extension == 'pdf') {
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => BillPdfViewerScreen(title: title, filePath: path),
        ),
      );
      return;
    }

    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => _SecureImagePreviewScreen(
          title: title,
          filePath: path,
        ),
      ),
    );
  } catch (_) {
    if (!context.mounted) return;
    Navigator.of(context, rootNavigator: true).pop();
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Unable to open document')),
    );
  }
}

class _SecureImagePreviewScreen extends StatelessWidget {
  const _SecureImagePreviewScreen({
    required this.title,
    required this.filePath,
  });

  final String title;
  final String filePath;

  @override
  Widget build(BuildContext context) {
    final exists = File(filePath).existsSync();

    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text(title, maxLines: 1, overflow: TextOverflow.ellipsis),
      ),
      body: exists
          ? Center(
              child: InteractiveViewer(
                child: Image.file(
                  File(filePath),
                  fit: BoxFit.contain,
                ),
              ),
            )
          : const Padding(
              padding: EdgeInsets.all(AppSpacing.screenPadding),
              child: PgErrorState(message: 'Image could not be loaded.'),
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
