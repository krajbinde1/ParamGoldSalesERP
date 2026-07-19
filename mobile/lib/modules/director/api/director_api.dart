import 'package:dio/dio.dart';
import '../../../core/api/api_errors.dart';

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
    required this.pendingClaims,
    required this.approvedClaims,
    required this.paidClaims,
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
  final int pendingClaims;
  final int approvedClaims;
  final int paidClaims;
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
      pendingClaims: int.tryParse('${taDa['pending_claims'] ?? 0}') ?? 0,
      approvedClaims: int.tryParse('${taDa['approved_claims'] ?? 0}') ?? 0,
      paidClaims: int.tryParse('${taDa['paid_claims'] ?? 0}') ?? 0,
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

  Future<List<Map<String, dynamic>>> listOrders({String? status}) async {
    try {
      final response = await _dio.get(
        '/director/orders',
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
}
