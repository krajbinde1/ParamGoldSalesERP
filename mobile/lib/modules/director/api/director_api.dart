import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../../../core/api/api_errors.dart';
import '../../manager/api/manager_api.dart';

class DirectorOrderListResult {
  const DirectorOrderListResult({
    required this.orders,
    required this.total,
    required this.counts,
    this.lastPage = 1,
  });

  final List<Map<String, dynamic>> orders;
  final int total;
  final ManagerOrderCounts counts;
  final int lastPage;
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
    this.todaySales = 0,
    this.todayCollection = 0,
    this.monthCollection = 0,
    this.totalOutstanding = 0,
    this.highOutstandingDealers = 0,
    this.activeEmployees = 0,
    this.punchedIn = 0,
    this.notPunchedIn = 0,
    this.activeRoutes = 0,
    this.noFieldActivityToday = 0,
    this.placedOrders = 0,
    this.sentForBillOrders = 0,
    this.billedOrders = 0,
    this.onHoldOrders = 0,
    this.revertedOrders = 0,
    this.myPendingPayments = 0,
    this.myPendingPaymentAmount = 0,
    this.nextPendingPayments = 0,
    this.paidTodayPayments = 0,
    this.paidTodayPaymentAmount = 0,
    this.topPerformers = const [],
    this.needsAttention = const [],
    this.hasMonitoring = false,
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
  final double todaySales;
  final double todayCollection;
  final double monthCollection;
  final double totalOutstanding;
  final int highOutstandingDealers;
  final int activeEmployees;
  final int punchedIn;
  final int notPunchedIn;
  final int activeRoutes;
  final int noFieldActivityToday;
  final int placedOrders;
  final int sentForBillOrders;
  final int billedOrders;
  final int onHoldOrders;
  final int revertedOrders;
  final int myPendingPayments;
  final double myPendingPaymentAmount;
  final int nextPendingPayments;
  final int paidTodayPayments;
  final double paidTodayPaymentAmount;
  final List<Map<String, dynamic>> topPerformers;
  final List<Map<String, dynamic>> needsAttention;
  final bool hasMonitoring;

  double get salesRemaining =>
      salesTarget > salesAchieved ? salesTarget - salesAchieved : 0;
  double get collectionRemaining => collectionTarget > collectionAchieved
      ? collectionTarget - collectionAchieved
      : 0;

  factory DirectorDashboardData.fromJson(Map<String, dynamic> json) {
    final summary = json['company_summary'] as Map? ?? {};
    final targets = summary['targets'] as Map? ?? {};
    final orders = summary['orders'] as Map? ?? {};
    final taDa = summary['ta_da'] as Map? ?? {};
    final operations = summary['operations'] as Map? ?? {};
    final paymentRequests = summary['payment_requests'] as Map? ?? {};
    final monitoring = json['monitoring'] as Map? ?? {};
    final pipeline = monitoring['pipeline'] as Map? ?? {};
    final payments = monitoring['payments'] as Map? ?? {};
    final teamPerf = monitoring['team_performance'] as Map? ?? {};
    final hasMonitoring = monitoring.isNotEmpty;

    final employeePerformance =
        (json['employee_performance'] as List?)
            ?.map((item) => Map<String, dynamic>.from(item as Map))
            .toList() ??
        const <Map<String, dynamic>>[];

    final presentToday =
        int.tryParse('${operations['present_today'] ?? 0}') ?? 0;
    final absentToday =
        int.tryParse('${operations['absent_today'] ?? 0}') ?? 0;
    final punchedIn = hasMonitoring
        ? int.tryParse('${monitoring['punched_in'] ?? presentToday}') ??
            presentToday
        : presentToday;
    final activeEmployees = hasMonitoring
        ? int.tryParse(
              '${monitoring['active_employees'] ?? punchedIn + absentToday}',
            ) ??
            (punchedIn + absentToday)
        : (punchedIn + absentToday);
    final notPunchedIn = hasMonitoring
        ? int.tryParse('${monitoring['not_punched_in'] ?? absentToday}') ??
            absentToday
        : absentToday;

    final pendingOrders =
        int.tryParse('${orders['pending_orders'] ?? 0}') ?? 0;
    final placedOrders = hasMonitoring
        ? int.tryParse('${pipeline['placed'] ?? pendingOrders}') ?? pendingOrders
        : pendingOrders;

    List<Map<String, dynamic>> top = (teamPerf['top'] as List?)
            ?.map((item) => Map<String, dynamic>.from(item as Map))
            .toList() ??
        const [];
    List<Map<String, dynamic>> needs = (teamPerf['needs_attention'] as List?)
            ?.map((item) => Map<String, dynamic>.from(item as Map))
            .toList() ??
        const [];
    if (top.isEmpty && employeePerformance.isNotEmpty) {
      final ranked = [...employeePerformance]..sort((a, b) {
          final ap =
              double.tryParse('${a['sales_percentage'] ?? 0}') ?? 0;
          final bp =
              double.tryParse('${b['sales_percentage'] ?? 0}') ?? 0;
          return bp.compareTo(ap);
        });
      top = ranked.take(5).toList(growable: false);
      needs = ranked.reversed
          .where((row) {
            final pct =
                double.tryParse('${row['sales_percentage'] ?? 0}') ?? 0;
            return pct < 50;
          })
          .take(5)
          .toList(growable: false);
    }

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
      pendingOrders: hasMonitoring
          ? int.tryParse('${monitoring['pending_orders'] ?? pendingOrders}') ??
              pendingOrders
          : pendingOrders,
      approvedOrders: hasMonitoring
          ? int.tryParse('${pipeline['approved'] ?? orders['approved_orders'] ?? 0}') ?? 0
          : int.tryParse('${orders['approved_orders'] ?? 0}') ?? 0,
      dispatchedOrders: hasMonitoring
          ? int.tryParse('${pipeline['dispatched'] ?? orders['dispatched_orders'] ?? 0}') ?? 0
          : int.tryParse('${orders['dispatched_orders'] ?? 0}') ?? 0,
      rejectedOrders: hasMonitoring
          ? int.tryParse('${pipeline['rejected'] ?? orders['rejected_orders'] ?? 0}') ?? 0
          : int.tryParse('${orders['rejected_orders'] ?? 0}') ?? 0,
      pendingClaims: int.tryParse('${taDa['pending_claims'] ?? 0}') ?? 0,
      approvedClaims: int.tryParse('${taDa['approved_claims'] ?? 0}') ?? 0,
      paidClaims: int.tryParse('${taDa['paid_claims'] ?? 0}') ?? 0,
      rejectedClaims: int.tryParse('${taDa['rejected_claims'] ?? 0}') ?? 0,
      pendingPaymentApprovals:
          int.tryParse('${paymentRequests['pending_approvals'] ?? payments['my_pending_count'] ?? 0}') ??
          0,
      presentToday: presentToday,
      absentToday: absentToday,
      dealerVisits: int.tryParse(
            '${monitoring['dealer_visits'] ?? operations['dealer_visits'] ?? 0}',
          ) ??
          0,
      fieldActivities: int.tryParse(
            '${monitoring['field_visits'] ?? operations['field_activities'] ?? 0}',
          ) ??
          0,
      collections: int.tryParse('${operations['collections'] ?? 0}') ?? 0,
      collectionAmount:
          double.tryParse('${operations['collection_amount'] ?? 0}') ?? 0,
      employeePerformance: employeePerformance,
      todaySales: double.tryParse('${monitoring['today_sales'] ?? 0}') ?? 0,
      todayCollection:
          double.tryParse('${monitoring['today_collection'] ?? 0}') ?? 0,
      monthCollection: double.tryParse(
            '${monitoring['month_collection'] ?? operations['collection_amount'] ?? 0}',
          ) ??
          0,
      totalOutstanding:
          double.tryParse('${monitoring['total_outstanding'] ?? 0}') ?? 0,
      highOutstandingDealers:
          int.tryParse('${monitoring['high_outstanding_dealers'] ?? 0}') ?? 0,
      activeEmployees: activeEmployees,
      punchedIn: punchedIn,
      notPunchedIn: notPunchedIn,
      activeRoutes: int.tryParse('${monitoring['active_routes'] ?? 0}') ?? 0,
      noFieldActivityToday:
          int.tryParse('${monitoring['no_field_activity_today'] ?? 0}') ?? 0,
      placedOrders: placedOrders,
      sentForBillOrders:
          int.tryParse('${pipeline['sent_for_bill'] ?? 0}') ?? 0,
      billedOrders: int.tryParse('${pipeline['billed'] ?? 0}') ?? 0,
      onHoldOrders: int.tryParse('${pipeline['on_hold'] ?? 0}') ?? 0,
      revertedOrders:
          int.tryParse('${pipeline['reverted_to_manager'] ?? 0}') ?? 0,
      myPendingPayments: int.tryParse(
            '${payments['my_pending_count'] ?? paymentRequests['pending_approvals'] ?? 0}',
          ) ??
          0,
      myPendingPaymentAmount:
          double.tryParse('${payments['my_pending_amount'] ?? 0}') ?? 0,
      nextPendingPayments:
          int.tryParse('${payments['next_count'] ?? 0}') ?? 0,
      paidTodayPayments:
          int.tryParse('${payments['paid_today_count'] ?? 0}') ?? 0,
      paidTodayPaymentAmount:
          double.tryParse('${payments['paid_today_amount'] ?? 0}') ?? 0,
      topPerformers: top,
      needsAttention: needs,
      hasMonitoring: hasMonitoring,
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
    int page = 1,
    int perPage = 20,
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
          'page': page,
          'per_page': perPage,
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
        lastPage: int.tryParse('${meta['last_page'] ?? 1}') ?? 1,
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

  Future<Map<String, dynamic>> getCollection(int collectionId) async {
    try {
      final response = await _dio.get('/director/collections/$collectionId');
      return Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<List<Map<String, dynamic>>> listDealerVisits({String? date}) async {
    try {
      final response = await _dio.get(
        '/director/dealer-visits',
        queryParameters: {
          if (date != null && date.isNotEmpty) 'date': date,
        },
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

  Future<Map<String, dynamic>> getDealerVisit(int visitId) async {
    try {
      final response = await _dio.get('/director/dealer-visits/$visitId');
      return Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }
}
