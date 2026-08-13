import 'package:dio/dio.dart';
import '../../../core/api/api_errors.dart';

class ManagerDashboardData {
  const ManagerDashboardData({
    required this.period,
    required this.pendingOrders,
    required this.approvedOrders,
    required this.dispatchedOrders,
    required this.pendingClaims,
    required this.approvedClaims,
    required this.salesTarget,
    required this.salesAchieved,
    required this.salesPercentage,
    required this.collectionTarget,
    required this.collectionAchieved,
    required this.collectionPercentage,
    required this.presentToday,
    required this.dealerVisits,
    required this.fieldActivities,
    required this.collections,
    required this.collectionAmount,
    required this.pendingOrderApprovals,
    required this.pendingTaDaApprovals,
    required this.employeePerformance,
  });

  final String period;
  final int pendingOrders;
  final int approvedOrders;
  final int dispatchedOrders;
  final int pendingClaims;
  final int approvedClaims;
  final double salesTarget;
  final double salesAchieved;
  final double salesPercentage;
  final double collectionTarget;
  final double collectionAchieved;
  final double collectionPercentage;
  final int presentToday;
  final int dealerVisits;
  final int fieldActivities;
  final int collections;
  final double collectionAmount;
  final List<Map<String, dynamic>> pendingOrderApprovals;
  final List<Map<String, dynamic>> pendingTaDaApprovals;
  final List<Map<String, dynamic>> employeePerformance;

  factory ManagerDashboardData.fromJson(Map<String, dynamic> json) {
    final targets = json['targets'] as Map? ?? {};
    final orders = json['orders'] as Map? ?? {};
    final taDa = json['ta_da'] as Map? ?? {};
    final operations = json['operations'] as Map? ?? {};
    final pendingFromOrders =
        int.tryParse('${orders['pending_orders'] ?? 0}') ?? 0;
    final pendingFromRoot =
        int.tryParse('${json['pending_order_approval_count'] ?? pendingFromOrders}') ??
        pendingFromOrders;

    return ManagerDashboardData(
      period: json['period']?.toString() ?? 'This Month',
      pendingOrders: pendingFromRoot,
      approvedOrders: int.tryParse('${orders['approved_orders'] ?? 0}') ?? 0,
      dispatchedOrders:
          int.tryParse('${orders['dispatched_orders'] ?? 0}') ?? 0,
      pendingClaims: int.tryParse('${taDa['pending_claims'] ?? 0}') ?? 0,
      approvedClaims: int.tryParse('${taDa['approved_claims'] ?? 0}') ?? 0,
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
      presentToday: int.tryParse(
            '${(json['modules'] as Map?)?['team_present_today'] ?? operations['present_today'] ?? 0}',
          ) ??
          0,
      dealerVisits: int.tryParse('${operations['dealer_visits'] ?? 0}') ?? 0,
      fieldActivities:
          int.tryParse('${operations['field_activities'] ?? 0}') ?? 0,
      collections: int.tryParse('${operations['collections'] ?? 0}') ?? 0,
      collectionAmount:
          double.tryParse('${operations['collection_amount'] ?? 0}') ?? 0,
      pendingOrderApprovals:
          (json['pending_order_approvals'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [],
      pendingTaDaApprovals:
          (json['pending_ta_da_approvals'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [],
      employeePerformance:
          (json['employee_performance'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [],
    );
  }
}

class ManagerOrderListResult {
  const ManagerOrderListResult({
    required this.orders,
    required this.total,
    required this.counts,
  });

  final List<Map<String, dynamic>> orders;
  final int total;
  final ManagerOrderCounts counts;
}

class ManagerOrderCounts {
  const ManagerOrderCounts({
    required this.pendingApproval,
    required this.approved,
    required this.rejected,
    required this.dispatched,
    required this.billed,
    required this.all,
  });

  final int pendingApproval;
  final int approved;
  final int rejected;
  final int dispatched;
  final int billed;
  final int all;

  int get placed => pendingApproval;

  factory ManagerOrderCounts.fromJson(Map<String, dynamic>? json) {
    final counts = json ?? const {};
    final pending =
        int.tryParse('${counts['pending_approval'] ?? counts['placed'] ?? 0}') ??
            0;
    return ManagerOrderCounts(
      pendingApproval: pending,
      approved: int.tryParse('${counts['approved'] ?? 0}') ?? 0,
      rejected: int.tryParse('${counts['rejected'] ?? 0}') ?? 0,
      dispatched: int.tryParse('${counts['dispatched'] ?? 0}') ?? 0,
      billed: int.tryParse('${counts['billed'] ?? 0}') ?? 0,
      all: int.tryParse('${counts['all'] ?? 0}') ?? 0,
    );
  }
}

class ManagerApi {
  const ManagerApi(this._dio);
  final Dio _dio;

  Future<ManagerDashboardData> loadDashboard({String period = 'month'}) async {
    try {
      final response = await _dio.get(
        '/manager/dashboard',
        queryParameters: {'period': period},
      );
      return ManagerDashboardData.fromJson(
        Map<String, dynamic>.from(response.data as Map),
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<ManagerOrderListResult> listOrders({
    String? status,
    String? salesPerson,
    String? dealer,
    String? orderNo,
    String? dateFrom,
    String? dateTo,
    String? search,
    int? salesEmployeeId,
  }) async {
    try {
      final response = await _dio.get(
        '/manager/orders',
        queryParameters: {
          if (status != null) 'status': status,
          if (salesPerson != null && salesPerson.isNotEmpty)
            'sales_person': salesPerson,
          if (dealer != null && dealer.isNotEmpty) 'dealer': dealer,
          if (orderNo != null && orderNo.isNotEmpty) 'order_no': orderNo,
          if (dateFrom != null && dateFrom.isNotEmpty) 'date_from': dateFrom,
          if (dateTo != null && dateTo.isNotEmpty) 'date_to': dateTo,
          if (search != null && search.isNotEmpty) 'search': search,
          if (salesEmployeeId != null) 'sales_employee_id': salesEmployeeId,
        },
      );
      final body = response.data as Map;
      final orders = (body['data'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [];
      final meta = body['meta'] as Map? ?? const {};
      return ManagerOrderListResult(
        orders: orders,
        total: int.tryParse('${meta['total'] ?? orders.length}') ?? orders.length,
        counts: ManagerOrderCounts.fromJson(
          body['counts'] as Map<String, dynamic>?,
        ),
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> getOrder(int orderId) async {
    try {
      final response = await _dio.get('/manager/orders/$orderId');
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
        '/manager/ta-da-claims',
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

  Future<ManagerTaDaListResult> listTaDaClaimsWithCounts({String? status}) async {
    try {
      final response = await _dio.get(
        '/manager/ta-da-claims',
        queryParameters: status != null ? {'status': status} : null,
      );
      final body = response.data as Map;
      final claims = (body['data'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [];
      final counts = body['counts'] as Map? ?? const {};
      return ManagerTaDaListResult(
        claims: claims,
        pending: int.tryParse('${counts['pending'] ?? 0}') ?? 0,
        approved: int.tryParse('${counts['approved'] ?? 0}') ?? 0,
        rejected: int.tryParse('${counts['rejected'] ?? 0}') ?? 0,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<ManagerTeamAttendanceListResult> listTeamAttendance({
    String? date,
    String? search,
  }) async {
    try {
      final response = await _dio.get(
        '/manager/team-attendance',
        queryParameters: {
          if (date != null) 'date': date,
          if (search != null && search.isNotEmpty) 'search': search,
        },
      );
      final body = response.data as Map;
      final rows = (body['data'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [];
      final meta = Map<String, dynamic>.from(body['meta'] as Map? ?? const {});
      return ManagerTeamAttendanceListResult(rows: rows, meta: meta);
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> getTeamAttendance(int attendanceId) async {
    try {
      final response = await _dio.get('/manager/team-attendance/$attendanceId');
      return Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<ManagerEmployeeAttendanceHistoryResult> getEmployeeAttendanceHistory(
    int employeeId, {
    String? month,
    String? dateFrom,
    String? dateTo,
  }) async {
    try {
      final response = await _dio.get(
        '/manager/team-attendance/employees/$employeeId',
        queryParameters: {
          if (month != null) 'month': month,
          if (dateFrom != null) 'date_from': dateFrom,
          if (dateTo != null) 'date_to': dateTo,
        },
      );
      final body = Map<String, dynamic>.from(response.data as Map);
      return ManagerEmployeeAttendanceHistoryResult.fromJson(body);
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<ManagerTeamActivityListResult> listTeamActivity({
    String? date,
    String? search,
    String type = 'all',
  }) async {
    try {
      final response = await _dio.get(
        '/manager/team-activity',
        queryParameters: {
          if (date != null) 'date': date,
          if (search != null && search.isNotEmpty) 'search': search,
          if (type.isNotEmpty) 'type': type,
        },
      );
      final body = response.data as Map;
      final rows = (body['data'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [];
      final meta = Map<String, dynamic>.from(body['meta'] as Map? ?? const {});
      return ManagerTeamActivityListResult(rows: rows, meta: meta);
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<ManagerTeamActivityTimelineResult> getEmployeeTeamActivity(
    int employeeId, {
    String? date,
    String type = 'all',
  }) async {
    try {
      final response = await _dio.get(
        '/manager/team-activity/employees/$employeeId',
        queryParameters: {
          if (date != null) 'date': date,
          if (type.isNotEmpty) 'type': type,
        },
      );
      final body = Map<String, dynamic>.from(response.data as Map);
      return ManagerTeamActivityTimelineResult.fromJson(body);
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> getTaDaClaim(int claimId) async {
    try {
      final response = await _dio.get('/manager/ta-da-claims/$claimId');
      return Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<void> approveOrder(int orderId, {String? remark}) async {
    try {
      await _dio.post(
        '/manager/orders/$orderId/approve',
        data: remark != null ? {'remark': remark} : null,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> updateOrder(
    int orderId,
    Map<String, dynamic> payload,
  ) async {
    try {
      final response = await _dio.put(
        '/manager/orders/$orderId',
        data: payload,
      );
      final body = response.data as Map;
      final data = body['data'];
      if (data is Map) {
        return Map<String, dynamic>.from(data);
      }
      return Map<String, dynamic>.from(body);
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<List<Map<String, dynamic>>> listProducts({String? search}) async {
    try {
      final response = await _dio.get(
        '/manager/products',
        queryParameters: {
          if (search != null && search.isNotEmpty) 'search': search,
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

  Future<void> rejectOrder(int orderId, {required String remark}) async {
    try {
      await _dio.post(
        '/manager/orders/$orderId/reject',
        data: {'remark': remark},
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<void> approveTaDaClaim(int claimId, {String? remark}) async {
    try {
      await _dio.post(
        '/manager/ta-da-claims/$claimId/approve',
        data: remark != null ? {'remark': remark} : null,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<void> rejectTaDaClaim(int claimId, {required String remark}) async {
    try {
      await _dio.post(
        '/manager/ta-da-claims/$claimId/reject',
        data: {'remark': remark},
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<ManagerEmployeePerformanceListResult> listEmployeePerformance({
    String period = 'month',
    String? startDate,
    String? endDate,
    String? search,
  }) async {
    try {
      final response = await _dio.get(
        '/manager/employees',
        queryParameters: {
          'period': period,
          if (startDate != null) 'start_date': startDate,
          if (endDate != null) 'end_date': endDate,
          if (search != null && search.isNotEmpty) 'search': search,
        },
      );
      final body = Map<String, dynamic>.from(response.data as Map);
      return ManagerEmployeePerformanceListResult.fromJson(body);
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  /// Team sales/collection targets — same employee rows as Team Performance.
  Future<ManagerTargetsResult> loadTargets({
    String period = 'month',
    String? startDate,
    String? endDate,
  }) async {
    try {
      final response = await _dio.get(
        '/manager/targets',
        queryParameters: {
          'period': period,
          if (startDate != null) 'start_date': startDate,
          if (endDate != null) 'end_date': endDate,
        },
      );
      return ManagerTargetsResult.fromJson(
        Map<String, dynamic>.from(response.data as Map),
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<ManagerEmployeePerformanceDetail> getEmployeePerformance(
    int employeeId, {
    String period = 'month',
    String? startDate,
    String? endDate,
  }) async {
    try {
      final response = await _dio.get(
        '/manager/employees/$employeeId',
        queryParameters: {
          'period': period,
          if (startDate != null) 'start_date': startDate,
          if (endDate != null) 'end_date': endDate,
        },
      );
      return ManagerEmployeePerformanceDetail.fromJson(
        Map<String, dynamic>.from(response.data as Map),
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }
}

class ManagerEmployeePerformanceListResult {
  const ManagerEmployeePerformanceListResult({
    required this.period,
    required this.periodKey,
    required this.employees,
  });

  final String period;
  final String periodKey;
  final List<Map<String, dynamic>> employees;

  factory ManagerEmployeePerformanceListResult.fromJson(
    Map<String, dynamic> json,
  ) {
    return ManagerEmployeePerformanceListResult(
      period: json['period']?.toString() ?? 'This Month',
      periodKey: json['period_key']?.toString() ?? 'month',
      employees: (json['data'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [],
    );
  }
}

class ManagerTargetsSummary {
  const ManagerTargetsSummary({
    required this.salesTarget,
    required this.salesAchieved,
    required this.salesPending,
    required this.salesPercentage,
    required this.collectionTarget,
    required this.collectionAchieved,
    required this.collectionPending,
    required this.collectionPercentage,
  });

  final double salesTarget;
  final double salesAchieved;
  final double salesPending;
  final double salesPercentage;
  final double collectionTarget;
  final double collectionAchieved;
  final double collectionPending;
  final double collectionPercentage;

  factory ManagerTargetsSummary.fromJson(Map<String, dynamic> json) {
    double n(Object? v) => double.tryParse('$v') ?? 0;
    return ManagerTargetsSummary(
      salesTarget: n(json['sales_target']),
      salesAchieved: n(json['sales_achieved']),
      salesPending: n(json['sales_pending']),
      salesPercentage: n(json['sales_percentage']),
      collectionTarget: n(json['collection_target']),
      collectionAchieved: n(json['collection_achieved']),
      collectionPending: n(json['collection_pending']),
      collectionPercentage: n(json['collection_percentage']),
    );
  }
}

class ManagerTargetsResult {
  const ManagerTargetsResult({
    required this.period,
    required this.periodKey,
    required this.summary,
    required this.employees,
  });

  final String period;
  final String periodKey;
  final ManagerTargetsSummary summary;
  final List<Map<String, dynamic>> employees;

  factory ManagerTargetsResult.fromJson(Map<String, dynamic> json) {
    return ManagerTargetsResult(
      period: json['period']?.toString() ?? 'This Month',
      periodKey: json['period_key']?.toString() ?? 'month',
      summary: ManagerTargetsSummary.fromJson(
        Map<String, dynamic>.from(json['summary'] as Map? ?? const {}),
      ),
      employees: (json['data'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [],
    );
  }
}

class ManagerEmployeePerformanceDetail {
  const ManagerEmployeePerformanceDetail({
    required this.period,
    required this.performance,
    required this.orders,
    required this.orderSummary,
  });

  final String period;
  final Map<String, dynamic> performance;
  final List<Map<String, dynamic>> orders;
  final Map<String, dynamic> orderSummary;

  factory ManagerEmployeePerformanceDetail.fromJson(
    Map<String, dynamic> json,
  ) {
    return ManagerEmployeePerformanceDetail(
      period: json['period']?.toString() ?? 'This Month',
      performance: Map<String, dynamic>.from(
        json['performance'] as Map? ?? const {},
      ),
      orders: (json['orders'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [],
      orderSummary: Map<String, dynamic>.from(
        json['order_summary'] as Map? ?? const {},
      ),
    );
  }
}

class ManagerTaDaListResult {
  const ManagerTaDaListResult({
    required this.claims,
    required this.pending,
    required this.approved,
    required this.rejected,
  });

  final List<Map<String, dynamic>> claims;
  final int pending;
  final int approved;
  final int rejected;
}

class ManagerTeamAttendanceListResult {
  const ManagerTeamAttendanceListResult({
    required this.rows,
    required this.meta,
  });

  final List<Map<String, dynamic>> rows;
  final Map<String, dynamic> meta;

  int get totalEmployees => int.tryParse('${meta['total_employees'] ?? 0}') ?? 0;
  int get punchedIn => int.tryParse('${meta['punched_in'] ?? 0}') ?? 0;
  int get punchedOut => int.tryParse('${meta['punched_out'] ?? 0}') ?? 0;
  int get notPunchedIn => int.tryParse('${meta['not_punched_in'] ?? 0}') ?? 0;
}

class ManagerEmployeeAttendanceHistoryResult {
  const ManagerEmployeeAttendanceHistoryResult({
    required this.employee,
    required this.rows,
    required this.meta,
  });

  final Map<String, dynamic> employee;
  final List<Map<String, dynamic>> rows;
  final Map<String, dynamic> meta;

  factory ManagerEmployeeAttendanceHistoryResult.fromJson(
    Map<String, dynamic> json,
  ) {
    return ManagerEmployeeAttendanceHistoryResult(
      employee: Map<String, dynamic>.from(json['employee'] as Map? ?? const {}),
      rows: (json['data'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [],
      meta: Map<String, dynamic>.from(json['meta'] as Map? ?? const {}),
    );
  }
}

class ManagerTeamActivityListResult {
  const ManagerTeamActivityListResult({
    required this.rows,
    required this.meta,
  });

  final List<Map<String, dynamic>> rows;
  final Map<String, dynamic> meta;

  int get totalDealerVisits =>
      int.tryParse('${meta['total_dealer_visits'] ?? 0}') ?? 0;
  int get totalFieldVisits =>
      int.tryParse('${meta['total_field_visits'] ?? 0}') ?? 0;
  int get activeEmployees =>
      int.tryParse('${meta['active_employees'] ?? 0}') ?? 0;
  int get totalEmployees =>
      int.tryParse('${meta['total_employees'] ?? 0}') ?? 0;
}

class ManagerTeamActivityTimelineResult {
  const ManagerTeamActivityTimelineResult({
    required this.employee,
    required this.rows,
    required this.meta,
  });

  final Map<String, dynamic> employee;
  final List<Map<String, dynamic>> rows;
  final Map<String, dynamic> meta;

  factory ManagerTeamActivityTimelineResult.fromJson(
    Map<String, dynamic> json,
  ) {
    return ManagerTeamActivityTimelineResult(
      employee: Map<String, dynamic>.from(json['employee'] as Map? ?? const {}),
      rows: (json['data'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [],
      meta: Map<String, dynamic>.from(json['meta'] as Map? ?? const {}),
    );
  }

  int get dealerVisitCount =>
      int.tryParse('${meta['dealer_visit_count'] ?? 0}') ?? 0;
  int get fieldVisitCount =>
      int.tryParse('${meta['field_visit_count'] ?? 0}') ?? 0;
}
