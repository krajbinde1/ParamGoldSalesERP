import 'dart:convert';
import 'dart:developer' as developer;

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../../../core/api/api_errors.dart';

class InventoryProductionApi {
  const InventoryProductionApi(this._dio);
  final Dio _dio;

  Map<String, dynamic> _body(Response response) =>
      Map<String, dynamic>.from(response.data as Map);

  dynamic _data(Response response) => _body(response)['data'];

  Future<Map<String, dynamic>> inventoryDashboard() async {
    try {
      final response = await _dio.get('/production/inventory/dashboard');
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> listRawMaterials({
    String? search,
    String? category,
    String? stockStatus,
    int page = 1,
  }) async {
    try {
      final response = await _dio.get(
        '/production/inventory/raw-materials',
        queryParameters: {
          if (search != null && search.isNotEmpty) 'search': search,
          if (category != null && category.isNotEmpty) 'category': category,
          if (stockStatus != null && stockStatus.isNotEmpty)
            'stock_status': stockStatus,
          'page': page,
        },
      );
      return _body(response);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  /// Master list — no stock qty/value fields for UI.
  Future<Map<String, dynamic>> listRawMaterialMasters({
    String? search,
    String? status,
    int page = 1,
  }) async {
    try {
      final response = await _dio.get(
        '/production/inventory/raw-materials',
        queryParameters: {
          'view': 'master',
          if (search != null && search.isNotEmpty) 'search': search,
          if (status != null && status.isNotEmpty) 'status': status,
          'page': page,
        },
      );
      return _body(response);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> getRawMaterial(int id) async {
    try {
      final response =
          await _dio.get('/production/inventory/raw-materials/$id');
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> createRawMaterial(
    Map<String, dynamic> payload,
  ) async {
    try {
      final response = await _dio.post(
        '/production/inventory/raw-materials',
        data: payload,
      );
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> updateRawMaterial(
    int id,
    Map<String, dynamic> payload,
  ) async {
    try {
      final response = await _dio.put(
        '/production/inventory/raw-materials/$id',
        data: payload,
      );
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> listPackagingMaterials({
    String? search,
    String? category,
    String? stockStatus,
    int page = 1,
  }) async {
    try {
      final response = await _dio.get(
        '/production/inventory/packaging-materials',
        queryParameters: {
          if (search != null && search.isNotEmpty) 'search': search,
          if (category != null && category.isNotEmpty) 'category': category,
          if (stockStatus != null && stockStatus.isNotEmpty)
            'stock_status': stockStatus,
          'page': page,
        },
      );
      return _body(response);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> listSemiFinishedMaterials({
    String? search,
    String? stockStatus,
    int page = 1,
  }) async {
    try {
      final response = await _dio.get(
        '/production/inventory/semi-finished',
        queryParameters: {
          if (search != null && search.isNotEmpty) 'search': search,
          if (stockStatus != null && stockStatus.isNotEmpty)
            'stock_status': stockStatus,
          'page': page,
        },
      );
      return _body(response);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> listFinishedGoods({
    String? search,
    String? stockStatus,
    int page = 1,
  }) async {
    try {
      final response = await _dio.get(
        '/production/inventory/finished-goods',
        queryParameters: {
          if (search != null && search.isNotEmpty) 'search': search,
          if (stockStatus != null && stockStatus.isNotEmpty)
            'stock_status': stockStatus,
          'page': page,
        },
      );
      return _body(response);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> stockLedgerBrowse({
    String? itemType,
    String? transactionType,
    String? search,
    String? batchNumber,
    String? from,
    String? to,
    int page = 1,
  }) async {
    try {
      final response = await _dio.get(
        '/production/inventory/stock-ledger',
        queryParameters: {
          if (itemType != null && itemType.isNotEmpty) 'item_type': itemType,
          if (transactionType != null && transactionType.isNotEmpty)
            'transaction_type': transactionType,
          if (search != null && search.isNotEmpty) 'search': search,
          if (batchNumber != null && batchNumber.isNotEmpty)
            'batch_number': batchNumber,
          if (from != null && from.isNotEmpty) 'from': from,
          if (to != null && to.isNotEmpty) 'to': to,
          'page': page,
        },
      );
      return _body(response);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> stockReport({
    String? inventoryType,
    String? itemKey,
    String? search,
    String? stockStatusFilter,
    int page = 1,
  }) async {
    try {
      final response = await _dio.get(
        '/production/inventory/stock-report',
        queryParameters: {
          if (inventoryType != null && inventoryType.isNotEmpty)
            'inventory_type': inventoryType,
          if (itemKey != null && itemKey.isNotEmpty) 'item_key': itemKey,
          if (search != null && search.isNotEmpty) 'search': search,
          if (stockStatusFilter != null && stockStatusFilter.isNotEmpty)
            'stock_status_filter': stockStatusFilter,
          'page': page,
        },
      );
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Map<String, dynamic> _ledgerQuery({
    required String itemType,
    required int itemId,
    String? from,
    String? to,
    String? transactionType,
    String? voucherNumber,
    String? productionBatch,
    int? page,
    int? perPage,
  }) =>
      {
        'item_type': itemType,
        'item_id': itemId,
        if (from != null && from.isNotEmpty) 'from': from,
        if (to != null && to.isNotEmpty) 'to': to,
        if (transactionType != null && transactionType.isNotEmpty)
          'transaction_type': transactionType,
        if (voucherNumber != null && voucherNumber.isNotEmpty)
          'voucher_number': voucherNumber,
        if (productionBatch != null && productionBatch.isNotEmpty)
          'production_batch': productionBatch,
        'page': ?page,
        'per_page': ?perPage,
      };

  Future<Map<String, dynamic>> itemLedger({
    required String itemType,
    required int itemId,
    String? from,
    String? to,
    String? transactionType,
    String? voucherNumber,
    String? productionBatch,
    int page = 1,
    int perPage = 200,
  }) async {
    try {
      final response = await _dio.get(
        '/production/inventory/ledger',
        queryParameters: _ledgerQuery(
          itemType: itemType,
          itemId: itemId,
          from: from,
          to: to,
          transactionType: transactionType,
          voucherNumber: voucherNumber,
          productionBatch: productionBatch,
          page: page,
          perPage: perPage,
        ),
      );
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  /// Downloads Excel via same StockItemLedgerExport as Filament web.
  Future<List<int>> exportItemLedgerExcel({
    required String itemType,
    required int itemId,
    String? from,
    String? to,
  }) async {
    try {
      final response = await _dio.get<List<int>>(
        '/production/inventory/ledger/export',
        queryParameters: _ledgerQuery(
          itemType: itemType,
          itemId: itemId,
          from: from,
          to: to,
        ),
        options: Options(
          responseType: ResponseType.bytes,
          receiveTimeout: const Duration(seconds: 60),
          headers: {'Accept': '*/*'},
        ),
      );
      return response.data ?? const [];
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  /// HTML print view — same blade as inventory.stock-item-ledger.print.
  Future<String> itemLedgerPrintHtml({
    required String itemType,
    required int itemId,
    String? from,
    String? to,
  }) async {
    try {
      final response = await _dio.get<String>(
        '/production/inventory/ledger/print',
        queryParameters: _ledgerQuery(
          itemType: itemType,
          itemId: itemId,
          from: from,
          to: to,
        ),
        options: Options(
          responseType: ResponseType.plain,
          receiveTimeout: const Duration(seconds: 60),
          headers: {'Accept': 'text/html'},
        ),
      );
      return response.data ?? '';
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  /// Downloads Item Stock Ledger PDF via authenticated API (Bearer header only).
  /// Never returns non-PDF payloads — validates status, Content-Type, and %PDF.
  Future<({List<int> bytes, String filename})> downloadItemLedgerPdf({
    required String itemType,
    required int itemId,
    String? from,
    String? to,
  }) async {
    final query = _ledgerQuery(
      itemType: itemType,
      itemId: itemId,
      from: from,
      to: to,
    );
    final safeUrl =
        '${_dio.options.baseUrl}/production/inventory/ledger/pdf'
        '?item_type=$itemType&item_id=$itemId'
        '${from != null ? '&from=$from' : ''}'
        '${to != null ? '&to=$to' : ''}';

    try {
      final response = await _dio.get<List<int>>(
        '/production/inventory/ledger/pdf',
        queryParameters: query,
        options: Options(
          responseType: ResponseType.bytes,
          receiveTimeout: const Duration(seconds: 90),
          validateStatus: (status) => status != null && status < 500,
          headers: {
            'Accept': 'application/pdf',
          },
        ),
      );

      final status = response.statusCode ?? 0;
      final contentType =
          (response.headers.value('content-type') ?? '').toLowerCase();
      final raw = response.data;
      final bytes = raw == null ? const <int>[] : List<int>.from(raw);
      final headerPreview = _pdfHeaderPreview(bytes);

      if (kDebugMode) {
        developer.log(
          'ledger PDF ← status=$status content-type=$contentType '
          'bytes=${bytes.length} header=$headerPreview url=$safeUrl',
          name: 'ItemStockLedgerPdf',
        );
      }

      if (status == 401 || status == 403 || status == 404 || status >= 400) {
        throw StateError(
          _safePdfErrorMessage(
            status: status,
            contentType: contentType,
            bytes: bytes,
            fallback: 'Ledger PDF request failed ($status).',
          ),
        );
      }

      if (status != 200) {
        throw StateError(
          'Unexpected ledger PDF status $status '
          '(content-type=$contentType, size=${bytes.length}).',
        );
      }

      if (!contentType.contains('application/pdf')) {
        throw StateError(
          _safePdfErrorMessage(
            status: status,
            contentType: contentType,
            bytes: bytes,
            fallback:
                'Ledger endpoint did not return application/pdf '
                '(got $contentType, size=${bytes.length}).',
          ),
        );
      }

      if (bytes.isEmpty) {
        throw StateError(
          'Ledger PDF body was empty '
          '(status=$status, content-type=$contentType).',
        );
      }

      if (!_looksLikePdf(bytes)) {
        throw StateError(
          _safePdfErrorMessage(
            status: status,
            contentType: contentType,
            bytes: bytes,
            fallback:
                'Ledger response was not a PDF '
                '(size=${bytes.length}, header=$headerPreview).',
          ),
        );
      }

      final disposition = response.headers.value('content-disposition') ?? '';
      final filename = _filenameFromDisposition(disposition) ??
          _defaultLedgerPdfFilename(
            itemId: itemId,
            from: from,
            to: to,
          );
      return (bytes: bytes, filename: filename);
    } on DioException catch (e) {
      final status = e.response?.statusCode;
      final contentType =
          (e.response?.headers.value('content-type') ?? '').toLowerCase();
      final data = e.response?.data;
      final bytes = data is List<int>
          ? data
          : (data is List ? List<int>.from(data.whereType<int>()) : const <int>[]);
      if (kDebugMode) {
        developer.log(
          'ledger PDF ✗ status=$status content-type=$contentType '
          'bytes=${bytes.length} url=$safeUrl err=${e.message}',
          name: 'ItemStockLedgerPdf',
        );
      }
      if (bytes.isNotEmpty && !_looksLikePdf(bytes)) {
        throw StateError(
          _safePdfErrorMessage(
            status: status ?? 0,
            contentType: contentType,
            bytes: bytes,
            fallback: errorMessage(mapApiError(e)),
          ),
        );
      }
      throw mapApiError(e);
    }
  }

  /// Downloads filtered Inventory Stock Report PDF (same filters as stock-report JSON).
  Future<({List<int> bytes, String filename})> downloadStockReportPdf({
    String? inventoryType,
    String? itemKey,
    String? search,
    String? stockStatusFilter,
  }) async {
    final query = <String, dynamic>{
      if (inventoryType != null && inventoryType.isNotEmpty)
        'inventory_type': inventoryType,
      if (itemKey != null && itemKey.isNotEmpty) 'item_key': itemKey,
      if (search != null && search.isNotEmpty) 'search': search,
      if (stockStatusFilter != null && stockStatusFilter.isNotEmpty)
        'stock_status_filter': stockStatusFilter,
    };
    final typePart =
        (inventoryType != null && inventoryType.isNotEmpty) ? inventoryType : 'all';
    final safeUrl =
        '${_dio.options.baseUrl}/production/inventory/stock-report/pdf'
        '?inventory_type=$typePart';

    try {
      final response = await _dio.get<List<int>>(
        '/production/inventory/stock-report/pdf',
        queryParameters: query,
        options: Options(
          responseType: ResponseType.bytes,
          receiveTimeout: const Duration(seconds: 90),
          validateStatus: (status) => status != null && status < 500,
          headers: {
            'Accept': 'application/pdf',
          },
        ),
      );

      final status = response.statusCode ?? 0;
      final contentType =
          (response.headers.value('content-type') ?? '').toLowerCase();
      final raw = response.data;
      final bytes = raw == null ? const <int>[] : List<int>.from(raw);
      final headerPreview = _pdfHeaderPreview(bytes);

      if (kDebugMode) {
        developer.log(
          'stock report PDF ← status=$status content-type=$contentType '
          'bytes=${bytes.length} header=$headerPreview url=$safeUrl',
          name: 'InventoryStockReportPdf',
        );
      }

      if (status == 401 || status == 403 || status == 404 || status >= 400) {
        throw StateError(
          _safePdfErrorMessage(
            status: status,
            contentType: contentType,
            bytes: bytes,
            fallback: 'Stock report PDF request failed ($status).',
          ),
        );
      }

      if (status != 200) {
        throw StateError(
          'Unexpected stock report PDF status $status '
          '(content-type=$contentType, size=${bytes.length}).',
        );
      }

      if (!contentType.contains('application/pdf')) {
        throw StateError(
          _safePdfErrorMessage(
            status: status,
            contentType: contentType,
            bytes: bytes,
            fallback:
                'Stock report endpoint did not return application/pdf '
                '(got $contentType, size=${bytes.length}).',
          ),
        );
      }

      if (bytes.isEmpty) {
        throw StateError(
          'Stock report PDF body was empty '
          '(status=$status, content-type=$contentType).',
        );
      }

      if (!_looksLikePdf(bytes)) {
        throw StateError(
          _safePdfErrorMessage(
            status: status,
            contentType: contentType,
            bytes: bytes,
            fallback:
                'Stock report response was not a PDF '
                '(size=${bytes.length}, header=$headerPreview).',
          ),
        );
      }

      final disposition = response.headers.value('content-disposition') ?? '';
      final filename = _filenameFromDisposition(disposition) ??
          _defaultStockReportPdfFilename(inventoryType: inventoryType);
      return (bytes: bytes, filename: filename);
    } on DioException catch (e) {
      final status = e.response?.statusCode;
      final contentType =
          (e.response?.headers.value('content-type') ?? '').toLowerCase();
      final data = e.response?.data;
      final bytes = data is List<int>
          ? data
          : (data is List ? List<int>.from(data.whereType<int>()) : const <int>[]);
      if (kDebugMode) {
        developer.log(
          'stock report PDF ✗ status=$status content-type=$contentType '
          'bytes=${bytes.length} url=$safeUrl err=${e.message}',
          name: 'InventoryStockReportPdf',
        );
      }
      if (bytes.isNotEmpty && !_looksLikePdf(bytes)) {
        throw StateError(
          _safePdfErrorMessage(
            status: status ?? 0,
            contentType: contentType,
            bytes: bytes,
            fallback: errorMessage(mapApiError(e)),
          ),
        );
      }
      throw mapApiError(e);
    }
  }

  String _defaultStockReportPdfFilename({String? inventoryType}) {
    final typeSlug = (inventoryType == null || inventoryType.isEmpty)
        ? 'All'
        : inventoryType
            .split('_')
            .map(
              (part) => part.isEmpty
                  ? part
                  : '${part[0].toUpperCase()}${part.substring(1)}',
            )
            .join('_');
    final now = DateTime.now();
    final stamp =
        '${now.day.toString().padLeft(2, '0')}-'
        '${now.month.toString().padLeft(2, '0')}-'
        '${now.year}';
    return 'Inventory_Stock_Report_${typeSlug}_$stamp.pdf';
  }

  bool _looksLikePdf(List<int> bytes) {
    return bytes.length >= 4 &&
        bytes[0] == 0x25 &&
        bytes[1] == 0x50 &&
        bytes[2] == 0x44 &&
        bytes[3] == 0x46;
  }

  String _pdfHeaderPreview(List<int> bytes) {
    final n = bytes.length < 12 ? bytes.length : 12;
    if (n == 0) return '(empty)';
    return String.fromCharCodes(
      bytes.take(n).map((b) => (b >= 32 && b < 127) ? b : 0x2E),
    );
  }

  String _safePdfErrorMessage({
    required int status,
    required String contentType,
    required List<int> bytes,
    required String fallback,
  }) {
    final parsed = _parseNonPdfErrorBody(bytes);
    final detail = parsed ?? fallback;
    // Never include tokens / auth headers — only status metadata + safe body.
    return '$detail (status=$status, content-type=$contentType, '
        'size=${bytes.length}).';
  }

  String? _parseNonPdfErrorBody(List<int> bytes) {
    if (bytes.isEmpty) return null;
    late final String text;
    try {
      text = utf8.decode(bytes, allowMalformed: true).trim();
    } catch (_) {
      return null;
    }
    if (text.isEmpty) return null;

    if (text.startsWith('{')) {
      try {
        final decoded = jsonDecode(text);
        if (decoded is Map) {
          final message = decoded['message']?.toString();
          if (message != null && message.isNotEmpty) {
            return message.length > 240 ? '${message.substring(0, 240)}…' : message;
          }
        }
      } catch (_) {}
    }

    final lower = text.toLowerCase();
    if (lower.contains('<html') || lower.contains('<!doctype')) {
      return 'Server returned an HTML page instead of a PDF';
    }
    if (text.length > 180) {
      return '${text.substring(0, 180)}…';
    }
    return text;
  }

  String? _filenameFromDisposition(String disposition) {
    final utfMatch =
        RegExp(r"filename\*=UTF-8''([^;]+)", caseSensitive: false)
            .firstMatch(disposition);
    if (utfMatch != null) {
      return Uri.decodeComponent(utfMatch.group(1)!.trim());
    }
    final plainMatch = RegExp(
      r'filename="?([^";]+)"?',
      caseSensitive: false,
    ).firstMatch(disposition);
    if (plainMatch != null) {
      return plainMatch.group(1)!.trim();
    }
    return null;
  }

  String _defaultLedgerPdfFilename({
    required int itemId,
    String? from,
    String? to,
  }) {
    final fromPart = (from != null && from.isNotEmpty) ? from : 'from';
    final toPart = (to != null && to.isNotEmpty) ? to : 'to';
    return 'Item_Stock_Ledger_${itemId}_${fromPart}_to_$toPart.pdf';
  }

  Future<List<Map<String, dynamic>>> shortages() async {
    try {
      final response = await _dio.get('/production/inventory/shortages');
      final data = Map<String, dynamic>.from(_data(response) as Map);
      return (data['items'] as List?)
              ?.map((e) => Map<String, dynamic>.from(e as Map))
              .toList() ??
          const [];
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<List<Map<String, dynamic>>> manufacturableProducts() async {
    try {
      final response = await _dio.get('/production/products/manufacturable');
      return (_data(response) as List?)
              ?.map((e) => Map<String, dynamic>.from(e as Map))
              .toList() ??
          const [];
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<List<Map<String, dynamic>>> manufacturableSemiFinished() async {
    try {
      final response =
          await _dio.get('/production/semi-finished/manufacturable');
      return (_data(response) as List?)
              ?.map((e) => Map<String, dynamic>.from(e as Map))
              .toList() ??
          const [];
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> listBoms({
    String? search,
    String? outputType,
    String status = 'active',
    int page = 1,
  }) async {
    try {
      final response = await _dio.get(
        '/production/boms',
        queryParameters: {
          if (search != null && search.isNotEmpty) 'search': search,
          if (outputType != null && outputType.isNotEmpty)
            'output_type': outputType,
          'status': status,
          'page': page,
        },
      );
      return _body(response);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> bomDetail(int bomId) async {
    try {
      final response = await _dio.get('/production/boms/$bomId');
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> activeBom({
    int? productId,
    int? semiFinishedId,
    String outputType = 'finished_product',
    double? plannedQuantity,
  }) async {
    try {
      final response = await _dio.get(
        '/production/boms/active',
        queryParameters: {
          'output_type': outputType,
          if (productId != null) 'product_id': productId,
          if (semiFinishedId != null) 'semi_finished_id': semiFinishedId,
          if (plannedQuantity != null) 'planned_quantity': plannedQuantity,
        },
      );
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> preview(Map<String, dynamic> payload) async {
    try {
      final response =
          await _dio.post('/production/batches/preview', data: payload);
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> confirmProduction(
    Map<String, dynamic> payload,
  ) async {
    try {
      final response =
          await _dio.post('/production/batches/confirm', data: payload);
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> listBatches({String? status, int page = 1}) async {
    try {
      final response = await _dio.get(
        '/production/batches',
        queryParameters: {
          if (status != null && status.isNotEmpty) 'status': status,
          'page': page,
        },
      );
      return _body(response);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> batchDetail(int batchId) async {
    try {
      final response = await _dio.get('/production/batches/$batchId');
      return Map<String, dynamic>.from(_data(response) as Map);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }

  Future<Map<String, dynamic>> history({
    String? outputType,
    int? productId,
    int? semiFinishedId,
    String? batchNumber,
    String? from,
    String? to,
    String? status,
    int page = 1,
  }) async {
    try {
      final response = await _dio.get(
        '/production/history',
        queryParameters: {
          if (outputType != null && outputType.isNotEmpty)
            'output_type': outputType,
          if (productId != null) 'product_id': productId,
          if (semiFinishedId != null) 'semi_finished_id': semiFinishedId,
          if (batchNumber != null && batchNumber.isNotEmpty)
            'batch_number': batchNumber,
          if (from != null && from.isNotEmpty) 'from': from,
          if (to != null && to.isNotEmpty) 'to': to,
          if (status != null && status.isNotEmpty) 'status': status,
          'page': page,
        },
      );
      return _body(response);
    } on DioException catch (e) {
      throw mapApiError(e);
    }
  }
}
