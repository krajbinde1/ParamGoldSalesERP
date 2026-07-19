import 'package:dio/dio.dart';
import '../models/order.dart';
import '../models/order_detail.dart';
import '../models/order_dashboard_data.dart';
import '../models/order_filter.dart';
import '../models/order_submit_result.dart';

class OrderApi {
  const OrderApi(this._dio);
  final Dio _dio;

  Future<OrderDashboardData> loadDashboard() async {
    final response = await _dio.get('/employee/orders');
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid order dashboard response.',
      );
    }

    final root = Map<String, dynamic>.from(body);
    final payload = root['data'] is Map
        ? Map<String, dynamic>.from(root['data'] as Map)
        : root;
    return OrderDashboardData.fromJson(payload);
  }

  Future<List<Order>> listOrders(OrderFilter filter) async {
    final response = await _dio.get(
      '/employee/orders',
      queryParameters: {'filter': filter.apiValue},
    );
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid order list response.',
      );
    }

    final rawOrders = body['orders'];
    if (rawOrders is! List) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid order list response.',
      );
    }

    return rawOrders
        .map((item) => Order.fromJson(Map<String, dynamic>.from(item as Map)))
        .toList();
  }

  Future<OrderDetail> getOrder(int orderId) async {
    final response = await _dio.get('/employee/orders/$orderId');
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid order detail response.',
      );
    }

    final root = Map<String, dynamic>.from(body);
    final payload = root['data'] is Map
        ? Map<String, dynamic>.from(root['data'] as Map)
        : root;
    return OrderDetail.fromJson(payload);
  }

  Future<OrderSubmitResult> submit(Map<String, dynamic> payload) async {
    try {
      final response = await _dio.post('/employee/orders', data: payload);
      final body = response.data;
      if (body is! Map) {
        throw DioException(
          requestOptions: response.requestOptions,
          message: 'Invalid order submit response.',
        );
      }
      return OrderSubmitResult.fromJson(Map<String, dynamic>.from(body));
    } on DioException catch (error) {
      throw _mapSubmitError(error);
    }
  }

  Future<OrderSubmitResult> update(
    int orderId,
    Map<String, dynamic> payload,
  ) async {
    try {
      final response = await _dio.put(
        '/employee/orders/$orderId',
        data: payload,
      );
      final body = response.data;
      if (body is! Map) {
        throw DioException(
          requestOptions: response.requestOptions,
          message: 'Invalid order update response.',
        );
      }
      return OrderSubmitResult.fromJson(Map<String, dynamic>.from(body));
    } on DioException catch (error) {
      throw _mapSubmitError(error);
    }
  }

  DioException _mapSubmitError(DioException error) {
    final data = error.response?.data;
    if (data is Map) {
      final message = data['message']?.toString();
      final errors = data['errors'];
      if (errors is Map && errors.isNotEmpty) {
        final details = errors.values
            .expand((value) => value is List ? value : [value])
            .map((value) => '$value')
            .join('\n');
        return DioException(
          requestOptions: error.requestOptions,
          response: error.response,
          message: details,
        );
      }
      if (message != null && message.isNotEmpty) {
        return DioException(
          requestOptions: error.requestOptions,
          response: error.response,
          message: message,
        );
      }
    }
    return error;
  }
}
