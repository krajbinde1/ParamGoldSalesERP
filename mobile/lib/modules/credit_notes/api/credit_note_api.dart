import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import '../../../core/api/api_errors.dart';
import '../models/credit_note.dart';

class CreditNoteApi {
  const CreditNoteApi(this._dio);
  final Dio _dio;

  Future<({Map<String, int> summary, List<CreditNoteListItem> recent})>
  loadDashboard() async {
    final response = await _dio.get('/employee/credit-notes');
    final body = Map<String, dynamic>.from(response.data as Map);
    final summaryRaw = body['summary'] is Map
        ? Map<String, dynamic>.from(body['summary'] as Map)
        : <String, dynamic>{};
    final recent = (body['recent_credit_notes'] as List? ?? const [])
        .whereType<Map>()
        .map(
          (item) =>
              CreditNoteListItem.fromJson(Map<String, dynamic>.from(item)),
        )
        .toList();
    return (
      summary: {
        'total': int.tryParse('${summaryRaw['total'] ?? 0}') ?? 0,
        'pending_approval':
            int.tryParse('${summaryRaw['pending_approval'] ?? 0}') ?? 0,
        'approved': int.tryParse('${summaryRaw['approved'] ?? 0}') ?? 0,
        'completed': int.tryParse('${summaryRaw['completed'] ?? 0}') ?? 0,
        'rejected': int.tryParse('${summaryRaw['rejected'] ?? 0}') ?? 0,
      },
      recent: recent,
    );
  }

  Future<List<CreditNoteListItem>> list({required String filter}) async {
    final response = await _dio.get(
      '/employee/credit-notes',
      queryParameters: {'filter': filter},
    );
    final body = Map<String, dynamic>.from(response.data as Map);
    return (body['credit_notes'] as List? ?? const [])
        .whereType<Map>()
        .map(
          (item) =>
              CreditNoteListItem.fromJson(Map<String, dynamic>.from(item)),
        )
        .toList();
  }

  Future<CreditNoteDetail> get(int id) async {
    final response = await _dio.get('/employee/credit-notes/$id');
    final body = Map<String, dynamic>.from(response.data as Map);
    return CreditNoteDetail.fromJson(
      Map<String, dynamic>.from(body['data'] as Map),
    );
  }

  Future<CreditNoteDetail> submit({
    required String type,
    required int dealerId,
    required String billReference,
    required DateTime creditNoteDate,
    required List<Map<String, dynamic>> items,
    String? remarks,
    String? documentPath,
    int? creditNoteId,
  }) async {
    try {
      final formData = FormData.fromMap({
        'type': type,
        'dealer_id': dealerId,
        'bill_reference': billReference,
        'credit_note_date':
            '${creditNoteDate.year.toString().padLeft(4, '0')}-'
            '${creditNoteDate.month.toString().padLeft(2, '0')}-'
            '${creditNoteDate.day.toString().padLeft(2, '0')}',
        'items': jsonEncode(items),
        if (remarks != null && remarks.trim().isNotEmpty)
          'remarks': remarks.trim(),
        if (documentPath != null && documentPath.isNotEmpty)
          'supporting_document': await MultipartFile.fromFile(
            documentPath,
            filename: File(documentPath).uri.pathSegments.last,
          ),
      });

      final Response response;
      if (creditNoteId == null) {
        response = await _dio.post('/employee/credit-notes', data: formData);
      } else {
        response = await _dio.post(
          '/employee/credit-notes/$creditNoteId',
          data: formData,
        );
      }

      final body = Map<String, dynamic>.from(response.data as Map);
      if (body['data'] is Map) {
        return CreditNoteDetail.fromJson(
          Map<String, dynamic>.from(body['data'] as Map),
        );
      }
      throw DioException(
        requestOptions: response.requestOptions,
        message: body['message']?.toString() ?? 'Credit Note saved.',
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }
}

class ManagerCreditNoteListResult {
  const ManagerCreditNoteListResult({
    required this.notes,
    required this.total,
    required this.counts,
  });

  final List<CreditNoteListItem> notes;
  final int total;
  final Map<String, int> counts;
}

class ManagerCreditNoteApi {
  const ManagerCreditNoteApi(this._dio);
  final Dio _dio;

  Future<ManagerCreditNoteListResult> list({String? status}) async {
    try {
      final response = await _dio.get(
        '/manager/credit-notes',
        queryParameters: {if (status != null) 'status': status},
      );
      final body = Map<String, dynamic>.from(response.data as Map);
      final countsRaw = body['counts'] is Map
          ? Map<String, dynamic>.from(body['counts'] as Map)
          : <String, dynamic>{};
      return ManagerCreditNoteListResult(
        notes: (body['data'] as List? ?? const [])
            .whereType<Map>()
            .map(
              (item) => CreditNoteListItem.fromJson(
                Map<String, dynamic>.from(item),
              ),
            )
            .toList(),
        total: int.tryParse('${(body['meta'] as Map?)?['total'] ?? 0}') ?? 0,
        counts: {
          'pending_approval':
              int.tryParse('${countsRaw['pending_approval'] ?? 0}') ?? 0,
          'approved': int.tryParse('${countsRaw['approved'] ?? 0}') ?? 0,
          'completed': int.tryParse('${countsRaw['completed'] ?? 0}') ?? 0,
          'rejected': int.tryParse('${countsRaw['rejected'] ?? 0}') ?? 0,
        },
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<CreditNoteDetail> get(int id) async {
    try {
      final response = await _dio.get('/manager/credit-notes/$id');
      final body = Map<String, dynamic>.from(response.data as Map);
      return CreditNoteDetail.fromJson(
        Map<String, dynamic>.from(body['data'] as Map),
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<CreditNoteDetail> update({
    required int id,
    required String type,
    required int dealerId,
    required String billReference,
    required DateTime creditNoteDate,
    required List<Map<String, dynamic>> items,
    String? remarks,
    String? documentPath,
  }) async {
    try {
      final formData = FormData.fromMap({
        'type': type,
        'dealer_id': dealerId,
        'bill_reference': billReference,
        'credit_note_date':
            '${creditNoteDate.year.toString().padLeft(4, '0')}-'
            '${creditNoteDate.month.toString().padLeft(2, '0')}-'
            '${creditNoteDate.day.toString().padLeft(2, '0')}',
        'items': jsonEncode(items),
        if (remarks != null && remarks.trim().isNotEmpty)
          'remarks': remarks.trim(),
        if (documentPath != null && documentPath.isNotEmpty)
          'supporting_document': await MultipartFile.fromFile(
            documentPath,
            filename: File(documentPath).uri.pathSegments.last,
          ),
      });
      final response = await _dio.post(
        '/manager/credit-notes/$id',
        data: formData,
      );
      final body = Map<String, dynamic>.from(response.data as Map);
      return CreditNoteDetail.fromJson(
        Map<String, dynamic>.from(body['data'] as Map),
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<void> approve(int id, {String? remark}) async {
    try {
      await _dio.post(
        '/manager/credit-notes/$id/approve',
        data: remark != null ? {'remark': remark} : null,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<void> reject(int id, {required String remark}) async {
    try {
      await _dio.post(
        '/manager/credit-notes/$id/reject',
        data: {'remark': remark},
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }
}
