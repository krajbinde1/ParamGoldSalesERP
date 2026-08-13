import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_pdfview/flutter_pdfview.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import '../design/app_spacing.dart';
import '../widgets/design/pg_empty_state.dart';

Future<void> openBillDocument(
  BuildContext context, {
  required String url,
  String title = 'Bill PDF',
}) async {
  if (url.trim().isEmpty) {
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Bill document is not available.')),
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
    final lower = url.toLowerCase();
    final isPdf = lower.contains('.pdf') || lower.contains('application/pdf');
    final extension = isPdf
        ? 'pdf'
        : (lower.contains('.png')
              ? 'png'
              : (lower.contains('.webp') ? 'webp' : 'jpg'));
    final path =
        '${dir.path}/order_bill_${DateTime.now().millisecondsSinceEpoch}.$extension';

    await Dio().download(url, path);

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

    await OpenFilex.open(path);
  } catch (error) {
    if (!context.mounted) return;
    Navigator.of(context, rootNavigator: true).pop();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('Unable to open bill: $error')),
    );
  }
}

class BillPdfViewerScreen extends StatelessWidget {
  const BillPdfViewerScreen({
    super.key,
    required this.title,
    required this.filePath,
  });

  final String title;
  final String filePath;

  @override
  Widget build(BuildContext context) {
    final exists = File(filePath).existsSync();

    return Scaffold(
      appBar: AppBar(
        title: Text(title),
        actions: [
          IconButton(
            tooltip: 'Open externally',
            onPressed: () => OpenFilex.open(filePath),
            icon: const Icon(Icons.open_in_new),
          ),
        ],
      ),
      body: exists
          ? PDFView(filePath: filePath)
          : Padding(
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              child: const PgErrorState(
                message: 'Bill file could not be loaded.',
              ),
            ),
    );
  }
}
