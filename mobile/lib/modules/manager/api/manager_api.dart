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

    return ManagerDashboardData(
      period: json['period']?.toString() ?? 'This Month',
      pendingOrders: int.tryParse('${orders['pending_orders'] ?? 0}') ?? 0,
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
      presentToday: int.tryParse('${operations['present_today'] ?? 0}') ?? 0,
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
    required this.dispatched,
  });

  final int pendingApproval;
  final int approved;
  final int dispatched;

  factory ManagerOrderCounts.fromJson(Map<String, dynamic>? json) {
    final counts = json ?? const {};
    return ManagerOrderCounts(
      pendingApproval:
          int.tryParse('${counts['pending_approval'] ?? 0}') ?? 0,
      approved: int.tryParse('${counts['approved'] ?? 0}') ?? 0,
      dispatched: int.tryParse('${counts['dispatched'] ?? 0}') ?? 0,
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

  Future<ManagerOrderListResult> listOrders({String? status}) async {
    try {
      final response = await _dio.get(
        '/manager/orders',
        queryParameters: status != null ? {'status': status} : null,
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
