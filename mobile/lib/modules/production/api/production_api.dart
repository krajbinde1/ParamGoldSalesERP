import 'package:dio/dio.dart';
import '../../../core/api/api_errors.dart';

class ProductionDashboardData {
  const ProductionDashboardData({
    required this.approvedOrders,
    required this.billedOrders,
    required this.readyForDispatch,
    required this.dispatchedOrders,
    required this.orders,
    required this.billedOrderList,
    required this.recentDispatched,
  });

  final int approvedOrders;
  final int billedOrders;
  final int readyForDispatch;
  final int dispatchedOrders;
  final List<Map<String, dynamic>> orders;
  final List<Map<String, dynamic>> billedOrderList;
  final List<Map<String, dynamic>> recentDispatched;

  factory ProductionDashboardData.fromJson(Map<String, dynamic> json) {
    final summary = json['summary'] as Map? ?? {};

    return ProductionDashboardData(
      approvedOrders: int.tryParse('${summary['approved_orders'] ?? 0}') ?? 0,
      billedOrders: int.tryParse('${summary['billed_orders'] ?? 0}') ?? 0,
      readyForDispatch:
          int.tryParse('${summary['ready_for_dispatch'] ?? 0}') ?? 0,
      dispatchedOrders:
          int.tryParse('${summary['dispatched_orders'] ?? 0}') ?? 0,
      orders: (json['approved_orders'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [],
      billedOrderList: (json['billed_orders'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [],
      recentDispatched: (json['recent_dispatched'] as List?)
              ?.map((item) => Map<String, dynamic>.from(item as Map))
              .toList() ??
          const [],
    );
  }
}

class ProductionOrderListResult {
  const ProductionOrderListResult({
    required this.orders,
    this.counts,
  });

  final List<Map<String, dynamic>> orders;
  final ProductionOrderCounts? counts;
}

class ProductionOrderCounts {
  const ProductionOrderCounts({
    required this.approved,
    required this.billed,
    required this.dispatched,
  });

  final int approved;
  final int billed;
  final int dispatched;

  factory ProductionOrderCounts.fromJson(Map<String, dynamic> json) =>
      ProductionOrderCounts(
        approved: int.tryParse('${json['approved'] ?? 0}') ?? 0,
        billed: int.tryParse('${json['billed'] ?? 0}') ?? 0,
        dispatched: int.tryParse('${json['dispatched'] ?? 0}') ?? 0,
      );
}

class ProductionApi {
  const ProductionApi(this._dio);
  final Dio _dio;

  Future<ProductionDashboardData> loadDashboard() async {
    try {
      final response = await _dio.get('/production/dashboard');
      return ProductionDashboardData.fromJson(
        Map<String, dynamic>.from(response.data as Map),
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<ProductionOrderListResult> listOrders({String? status}) async {
    try {
      final response = await _dio.get(
        '/production/orders',
        queryParameters: status != null ? {'status': status} : null,
      );
      final body = response.data as Map;
      final meta = body['meta'] is Map
          ? Map<String, dynamic>.from(body['meta'] as Map)
          : <String, dynamic>{};
      final countsJson = meta['counts'] is Map
          ? Map<String, dynamic>.from(meta['counts'] as Map)
          : null;

      return ProductionOrderListResult(
        orders: (body['data'] as List?)
                ?.map((item) => Map<String, dynamic>.from(item as Map))
                .toList() ??
            const [],
        counts: countsJson == null
            ? null
            : ProductionOrderCounts.fromJson(countsJson),
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> getOrder(int orderId) async {
    try {
      final response = await _dio.get('/production/orders/$orderId');
      return Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> calculateDispatch(
    int orderId, {
    required String transportType,
    required double transportAmount,
  }) async {
    try {
      final response = await _dio.post(
        '/production/orders/$orderId/dispatch-calculation',
        data: {
          'transport_type': transportType,
          'transport_amount': transportAmount,
        },
      );
      return Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> dispatchOrder(
    int orderId, {
    required String transportType,
    required double transportAmount,
    String? remark,
  }) async {
    try {
      final response = await _dio.post(
        '/production/orders/$orderId/dispatch',
        data: {
          'transport_type': transportType,
          'transport_amount': transportAmount,
          if (remark != null) 'remark': remark,
        },
      );
      return Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }
}
