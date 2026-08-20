import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import '../../../core/api/api_config.dart';
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
    required this.sentForBill,
    required this.billed,
    required this.dispatched,
    this.rejected = 0,
  });

  final int approved;
  final int sentForBill;
  final int billed;
  final int dispatched;
  final int rejected;

  factory ProductionOrderCounts.fromJson(Map<String, dynamic> json) =>
      ProductionOrderCounts(
        approved: int.tryParse('${json['approved'] ?? 0}') ?? 0,
        sentForBill:
            int.tryParse(
                  '${json['sent_for_bill'] ?? json['pending_for_billing'] ?? 0}',
                ) ??
                0,
        billed: int.tryParse('${json['billed'] ?? 0}') ?? 0,
        dispatched: int.tryParse('${json['dispatched'] ?? 0}') ?? 0,
        rejected: int.tryParse('${json['rejected'] ?? 0}') ?? 0,
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
    // TEMP DEBUG: Production Supervisor → Approved Orders only.
    final isApprovedOrdersDebug = status == 'approved';

    void log(String message) {
      // ignore: avoid_print
      print(message);
      debugPrint(message);
    }

    try {
      if (isApprovedOrdersDebug) {
        log(
          '[PS ApprovedOrders DEBUG] ApiConfig.baseUrl=${ApiConfig.baseUrl}',
        );
        final query = status != null && status.isNotEmpty
            ? {'status': status}
            : null;
        final previewUri = Uri.parse(
          '${_dio.options.baseUrl}/production/orders',
        ).replace(queryParameters: query);
        log(
          '[PS ApprovedOrders DEBUG] Full API URL called: $previewUri',
        );
      }

      final response = await _dio.get(
        '/production/orders',
        queryParameters: status != null && status.isNotEmpty
            ? {'status': status}
            : null,
      );

      if (isApprovedOrdersDebug) {
        log(
          '[PS ApprovedOrders DEBUG] HTTP status code: ${response.statusCode}',
        );
        log(
          '[PS ApprovedOrders DEBUG] Full API URL (resolved): '
          '${response.requestOptions.uri}',
        );
        log(
          '[PS ApprovedOrders DEBUG] Raw response body: '
          '${_debugEncodeBody(response.data)}',
        );
      }

      final raw = response.data;
      if (raw is! Map) {
        if (isApprovedOrdersDebug) {
          log(
            '[PS ApprovedOrders DEBUG] Parsing exception: '
            'Unexpected orders response format '
            '(type=${raw.runtimeType}).',
          );
        }
        throw DioException(
          requestOptions: response.requestOptions,
          message: 'Unexpected orders response format.',
          response: response,
        );
      }

      final body = Map<String, dynamic>.from(raw);
      late final List<Map<String, dynamic>> orders;
      late final ProductionOrderCounts? counts;
      try {
        orders = _extractOrderMaps(body);
        counts = _extractCounts(body);
      } catch (error, stackTrace) {
        if (isApprovedOrdersDebug) {
          log(
            '[PS ApprovedOrders DEBUG] Parsing/filter exception: $error',
          );
          log(
            '[PS ApprovedOrders DEBUG] Stack: $stackTrace',
          );
        }
        rethrow;
      }

      if (isApprovedOrdersDebug) {
        log(
          '[PS ApprovedOrders DEBUG] Parsed approved order count: '
          '${orders.length}',
        );
        for (final order in orders) {
          log(
            '[PS ApprovedOrders DEBUG] order '
            'id=${order['id']} '
            'order_number=${order['order_no'] ?? order['order_number']} '
            'status=${order['status']}',
          );
        }
      }

      return ProductionOrderListResult(
        orders: orders,
        counts: counts,
      );
    } on DioException catch (error) {
      if (isApprovedOrdersDebug) {
        log(
          '[PS ApprovedOrders DEBUG] DioException: '
          'status=${error.response?.statusCode} '
          'url=${error.requestOptions.uri} '
          'message=${error.message} '
          'body=${_debugEncodeBody(error.response?.data)}',
        );
      }
      throw mapApiError(error);
    } catch (error, stackTrace) {
      if (isApprovedOrdersDebug) {
        log(
          '[PS ApprovedOrders DEBUG] Parsing/filter exception: $error',
        );
        log(
          '[PS ApprovedOrders DEBUG] Stack: $stackTrace',
        );
      }
      rethrow;
    }
  }

  static String _debugEncodeBody(Object? data) {
    try {
      if (data == null) return 'null';
      if (data is String) return data;
      return jsonEncode(data);
    } catch (error) {
      return '<<unencodable body: $error | $data>>';
    }
  }

  static ProductionOrderCounts? _extractCounts(Map<String, dynamic> body) {
    Map<String, dynamic>? meta;
    if (body['meta'] is Map) {
      meta = Map<String, dynamic>.from(body['meta'] as Map);
    }

    final nestedData = body['data'];
    if (meta == null && nestedData is Map && nestedData['meta'] is Map) {
      meta = Map<String, dynamic>.from(nestedData['meta'] as Map);
    }

    final countsJson = meta?['counts'];
    if (countsJson is Map) {
      return ProductionOrderCounts.fromJson(
        Map<String, dynamic>.from(countsJson),
      );
    }
    return null;
  }

  static List<Map<String, dynamic>> _extractOrderMaps(
    Map<String, dynamic> body,
  ) {
    dynamic listRaw = body['data'] ?? body['orders'] ?? body['items'];

    // Laravel paginator / double-wrapped payloads: { data: { data: [...] } }
    if (listRaw is Map) {
      listRaw = listRaw['data'] ?? listRaw['orders'] ?? listRaw['items'];
    }

    final orders = <Map<String, dynamic>>[];
    if (listRaw is List) {
      for (final item in listRaw) {
        if (item is Map) {
          orders.add(Map<String, dynamic>.from(item));
        }
      }
    }
    return orders;
  }

  /// Statuses that belong on the Production "Approved Orders" tab.
  static bool isApprovedTabStatus(String? status) {
    final value = (status ?? '').trim().toLowerCase();
    return value == 'approved' ||
        value == 'approved_by_manager' ||
        value == 'manager_approved' ||
        value == 'approved_by_sales_manager';
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

  Future<Map<String, dynamic>> sendForBill(
    int orderId, {
    int? vehicleId,
    String? vehicleNumber,
    required double transportFreight,
    String? transportRemark,
  }) async {
    try {
      final response = await _dio.post(
        '/production/orders/$orderId/send-for-bill',
        data: {
          if (vehicleId != null) 'vehicle_id': vehicleId,
          if (vehicleNumber != null && vehicleNumber.trim().isNotEmpty)
            'vehicle_number': vehicleNumber.trim(),
          'transport_freight': transportFreight,
          if (transportRemark != null && transportRemark.trim().isNotEmpty)
            'transport_remark': transportRemark.trim(),
        },
      );
      return Map<String, dynamic>.from(
        (response.data as Map)['data'] as Map,
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<List<Map<String, dynamic>>> listVehicles({String? search}) async {
    try {
      final response = await _dio.get(
        '/production/vehicles',
        queryParameters: search != null && search.trim().isNotEmpty
            ? {'search': search.trim()}
            : null,
      );
      final data = (response.data as Map)['data'];
      if (data is! List) return const [];
      return data
          .whereType<Map>()
          .map((item) => Map<String, dynamic>.from(item))
          .toList();
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<Map<String, dynamic>> createVehicle({
    required String vehicleNumber,
    String? vehicleName,
    String? vehicleType,
  }) async {
    try {
      final response = await _dio.post(
        '/production/vehicles',
        data: {
          'vehicle_number': vehicleNumber.trim(),
          if (vehicleName != null && vehicleName.trim().isNotEmpty)
            'vehicle_name': vehicleName.trim(),
          if (vehicleType != null && vehicleType.trim().isNotEmpty)
            'vehicle_type': vehicleType.trim(),
        },
      );
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
    String? transportType,
    double? transportAmount,
    String? remark,
  }) async {
    try {
      final response = await _dio.post(
        '/production/orders/$orderId/dispatch',
        data: {
          if (transportType != null) 'transport_type': transportType,
          if (transportAmount != null) 'transport_amount': transportAmount,
          if (remark != null && remark.trim().isNotEmpty) 'remark': remark.trim(),
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
