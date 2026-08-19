import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../../../core/api/api_errors.dart';
import '../../manager/api/manager_api.dart';

class DirectorOrderListResult {
  const DirectorOrderListResult({
    required this.orders,
    required this.total,
    required this.counts,
  });

  final List<Map<String, dynamic>> orders;
  final int total;
  final ManagerOrderCounts counts;
}

class DirectorRouteTrackingListResult {
  const DirectorRouteTrackingListResult({
    required this.rows,
    required this.meta,
  });

  final List<Map<String, dynamic>> rows;
  final Map<String, dynamic> meta;
}

class DirectorDashboardData {
  const DirectorDashboardData({
    required this.period,
    required this.salesTarget,
    required this.salesAchieved,
    required this.salesPercentage,
    required this.collectionTarget,
    required this.collectionAchieved,
    required this.collectionPercentage,
    required this.pendingOrders,
    required this.approvedOrders,
    required this.dispatchedOrders,
    required this.rejectedOrders,
    required this.pendingClaims,
    required this.approvedClaims,
    required this.paidClaims,
    required this.rejectedClaims,
    required this.pendingPaymentApprovals,
    required this.presentToday,
    required this.absentToday,
    required this.dealerVisits,
    required this.fieldActivities,
    required this.collections,
    required this.collectionAmount,
    required this.employeePerformance,
  });

  final String period;
  final double salesTarget;
  final double salesAchieved;
  final double salesPercentage;
  final double collectionTarget;
  final double collectionAchieved;
  final double collectionPercentage;
  final int pendingOrders;
  final int approvedOrders;
  final int dispatchedOrders;
  final int rejectedOrders;
  final int pendingClaims;
  final int approvedClaims;
  final int paidClaims;
  final int rejectedClaims;
  final int pendingPaymentApprovals;
  final int presentToday;
  final int absentToday;
  final int dealerVisits;
  final int fieldActivities;
  final int collections;
  final double collectionAmount;
  final List<Map<String, dynamic>> employeePerformance;

  factory DirectorDashboardData.fromJson(Map<String, dynamic> json) {
    final summary = json['company_summary'] as Map? ?? {};
    final targets = summary['targets'] as Map? ?? {};
    final orders = summary['orders'] as Map? ?? {};
    final taDa = summary['ta_da'] as Map? ?? {};
    final operations = summary['operations'] as Map? ?? {};
    final paymentRequests = summary['payment_requests'] as Map? ?? {};

    return DirectorDashboardData(
      period: json['period']?.toString() ?? 'This Month',
      salesTarget: double.tryParse('${targets['sales_target'] ?? 0}') ?? 0,
      salesAchieved: double.tryParse('${targets['sales_achieved'] ?? 0}') ?? 0,
      salesPercentage:
          double.tryParse('${targets['sales_percentage'] ?? 0}') ?? 0,
      collectionTarget:
          double.tryParse('${targets['collection_target'] ?? 0}') ?? 0,
      collectionAchieved:
          double.tryParse('${targets['collection_achieved'] ?? 0}') ?? 0,
      collectionPercentage:
          double.tryParse('${targets['collection_percentage'] ?? 0}') ?? 0,
      pendingOrders: int.tryParse('${orders['pending_orders'] ?? 0}') ?? 0,
      approvedOrders: int.tryParse('${orders['approved_orders'] ?? 0}') ?? 0,
      dispatchedOrders:
          int.tryParse('${orders['dispatched_orders'] ?? 0}') ?? 0,
      rejectedOrders: int.tryParse('${orders['rejected_orders'] ?? 0}') ?? 0,
      pendingClaims: int.tryParse('${taDa['pending_claims'] ?? 0}') ?? 0,
      approvedClaims: int.tryParse('${taDa['approved_claims'] ?? 0}') ?? 0,
      paidClaims: int.tryParse('${taDa['paid_claims'] ?? 0}') ?? 0,
      rejectedClaims: int.tryParse('${taDa['rejected_claims'] ?? 0}') ?? 0,
      pendingPaymentApprovals:
          int.tryParse('${paymentRequests['pending_approvals'] ?? 0}') ?? 0,
      presentToday: int.tryParse('${operations['present_today'] ?? 0}') ?? 0,
      absentToday: int.tryParse('${operations['absent_today'] ?? 0}') ?? 0,
      dealerVisits: int.tryParse('${operations['dealer_visits'] ?? 0}') ?? 0,
      fieldActivities:
          int.tryParse('${operations['field_activities'] ?? 0}') ?? 0,
      collections: int.tryParse('${operations['collections'] ?? 0}') ?? 0,
      collectionAmount:
          double.tryParse('${operations['collection_amount'] ?? 0}') ?? 0,
      employeePerformance:
          (json['employee_performance'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [],
    );
  }
}

class DirectorApi {
  const DirectorApi(this._dio);
  final Dio _dio;

  Future<DirectorDashboardData> loadDashboard({
    String period = 'month',
    String? role,
  }) async {
    try {
      final response = await _dio.get(
        '/director/dashboard',
        queryParameters: {
          'period': period,
          if (role != null) 'role': role,
        },
      );
      return DirectorDashboardData.fromJson(
        Map<String, dynamic>.from(response.data as Map),
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<DirectorOrderListResult> listOrders({
    String? status,
    String? search,
    String? salesPerson,
    String? dealer,
    String? orderNo,
    String? dateFrom,
    String? dateTo,
  }) async {
    try {
      final response = await _dio.get(
        '/director/orders',
        queryParameters: {
          if (status != null) 'status': status,
          if (search != null && search.isNotEmpty) 'search': search,
          if (salesPerson != null && salesPerson.isNotEmpty)
            'sales_person': salesPerson,
          if (dealer != null && dealer.isNotEmpty) 'dealer': dealer,
          if (orderNo != null && orderNo.isNotEmpty) 'order_no': orderNo,
          if (dateFrom != null && dateFrom.isNotEmpty) 'date_from': dateFrom,
          if (dateTo != null && dateTo.isNotEmpty) 'date_to': dateTo,
        },
      );
      final body = response.data as Map;
      final orders = (body['data'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [];
      final meta = body['meta'] as Map? ?? const {};
      return DirectorOrderListResult(
        orders: orders,
        total: int.tryParse('${meta['total'] ?? orders.length}') ?? orders.length,
        counts: ManagerOrderCounts.fromJson(
          body['counts'] is Map
              ? Map<String, dynamic>.from(body['counts'] as Map)
              : null,
        ),
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> getOrder(int orderId) async {
    try {
      final response = await _dio.get('/director/orders/$orderId');
      return Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<List<Map<String, dynamic>>> listTaDaClaims({String? status}) async {
    try {
      final response = await _dio.get(
        '/director/ta-da-claims',
        queryParameters: status != null ? {'status': status} : null,
      );
      final body = response.data as Map;
      return (body['data'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [];
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> getTaDaClaim(int claimId) async {
    try {
      final response = await _dio.get('/director/ta-da-claims/$claimId');
      return Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<
      ({
        int pendingCount,
        double pendingTotalAmount,
        List<Map<String, dynamic>> data
      })> listPaymentRequests({String? status}) async {
    try {
      final response = await _dio.get(
        '/director/payment-requests',
        queryParameters: status != null ? {'status': status} : null,
      );
      final body = Map<String, dynamic>.from(response.data as Map);
      return (
        pendingCount: int.tryParse('${body['pending_count'] ?? 0}') ?? 0,
        pendingTotalAmount:
            double.tryParse('${body['pending_total_amount'] ?? 0}') ?? 0,
        data: (body['data'] as List?)
                ?.map((item) => Map<String, dynamic>.from(item as Map))
                .toList() ??
            const [],
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> getPaymentRequest(int id) async {
    try {
      debugPrint('PARAMGOLD_PAYMENT_DETAIL_REQUEST id=$id');
      final response = await _dio.get('/director/payment-requests/$id');
      debugPrint(
        'PARAMGOLD_PAYMENT_DETAIL_RESPONSE id=$id status=${response.statusCode}',
      );
      final root = response.data;
      if (root is! Map) {
        throw StateError('Invalid payment request response');
      }
      final body = Map<String, dynamic>.from(root);
      final data = body['data'];
      if (data is! Map) {
        throw StateError('Payment request data missing');
      }
      return Map<String, dynamic>.from(data);
    } on DioException catch (error) {
      debugPrint(
        'PARAMGOLD_PAYMENT_DETAIL_ERROR id=$id '
        'status=${error.response?.statusCode}',
      );
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> approvePaymentRequest(int id) async {
    try {
      final response = await _dio.post('/director/payment-requests/$id/approve');
      return Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> approvePaymentRequestsBulk({
    List<int>? ids,
    bool approveAllPending = false,
  }) async {
    try {
      final response = await _dio.post(
        '/director/payment-requests/approve-bulk',
        data: {
          if (ids != null) 'ids': ids,
          'approve_all_pending': approveAllPending,
        },
      );
      return Map<String, dynamic>.from(response.data as Map);
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> rejectPaymentRequest(
    int id, {
    required String remark,
  }) async {
    try {
      final response = await _dio.post(
        '/director/payment-requests/$id/reject',
        data: {'remark': remark},
      );
      return Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<DirectorRouteTrackingListResult> listRouteTracking({
    String? date,
    String? search,
  }) async {
    try {
      debugPrint(
        'PARAMGOLD_DIRECTOR_ROUTE_LIST date=$date',
      );
      final response = await _dio.get(
        '/director/route-tracking',
        queryParameters: {
          if (date != null) 'date': date,
          if (search != null && search.isNotEmpty) 'search': search,
        },
      );
      debugPrint(
        'PARAMGOLD_DIRECTOR_ROUTE_LIST status=${response.statusCode}',
      );
      final raw = response.data;
      if (raw is! Map) {
        throw StateError('Invalid route tracking response');
      }
      final body = Map<String, dynamic>.from(raw);
      final dataRaw = body['data'];
      final rows = dataRaw is List
          ? dataRaw
              .whereType<Map>()
              .map((item) => Map<String, dynamic>.from(item))
              .toList()
          : <Map<String, dynamic>>[];
      final metaRaw = body['meta'];
      return DirectorRouteTrackingListResult(
        rows: rows,
        meta: metaRaw is Map
            ? Map<String, dynamic>.from(metaRaw)
            : <String, dynamic>{},
      );
    } on DioException catch (error) {
      debugPrint(
        'PARAMGOLD_DIRECTOR_ROUTE_LIST_ERROR '
        'status=${error.response?.statusCode} date=$date',
      );
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> getRouteTracking(int attendanceId) async {
    try {
      debugPrint(
        'PARAMGOLD_DIRECTOR_ROUTE_DETAIL id=$attendanceId',
      );
      final response = await _dio.get('/director/route-tracking/$attendanceId');
      final root = response.data;
      if (root is! Map) {
        throw StateError('Invalid route detail response');
      }
      final data = root['data'];
      if (data is! Map) {
        throw StateError('Route detail data missing');
      }
      return Map<String, dynamic>.from(data);
    } on DioException catch (error) {
      debugPrint(
        'PARAMGOLD_DIRECTOR_ROUTE_DETAIL_ERROR '
        'id=$attendanceId status=${error.response?.statusCode}',
      );
      throw mapApiError(error);
    }
  }
}
