import 'order.dart';

class OrderDashboardData {
  const OrderDashboardData({
    required this.totalOrders,
    required this.pendingOrders,
    required this.dispatchedOrders,
    required this.rejectedOrders,
    required this.recentOrders,
    required this.apiAvailable,
  });

  final int totalOrders;
  final int pendingOrders;
  final int dispatchedOrders;
  final int rejectedOrders;
  final List<Order> recentOrders;
  final bool apiAvailable;

  factory OrderDashboardData.fromJson(Map<String, dynamic> json) {
    final summary = json['summary'] is Map
        ? Map<String, dynamic>.from(json['summary'] as Map)
        : null;
    final rawOrders = json['recent_orders'] ?? json['orders'] ?? json['data'];
    final orders = rawOrders is List
        ? rawOrders
              .map(
                (item) =>
                    Order.fromJson(Map<String, dynamic>.from(item as Map)),
              )
              .toList()
        : const <Order>[];

    if (summary != null) {
      return OrderDashboardData(
        totalOrders: _asInt(summary['total_orders']) ?? orders.length,
        pendingOrders: _asInt(summary['pending_orders']) ?? 0,
        dispatchedOrders: _asInt(summary['dispatched_orders']) ?? 0,
        rejectedOrders: _asInt(summary['rejected_orders']) ?? 0,
        recentOrders: orders,
        apiAvailable: true,
      );
    }

    return OrderDashboardData.fromOrders(orders);
  }

  factory OrderDashboardData.fromOrders(List<Order> orders) {
    var pending = 0;
    var dispatched = 0;
    var rejected = 0;

    for (final order in orders) {
      if (OrderStatusRules.isPendingBucket(order.status)) pending++;
      if (OrderStatusRules.isDispatchedBucket(order.status)) dispatched++;
      if (OrderStatusRules.isRejectedBucket(order.status)) rejected++;
    }

    return OrderDashboardData(
      totalOrders: orders.length,
      pendingOrders: pending,
      dispatchedOrders: dispatched,
      rejectedOrders: rejected,
      recentOrders: orders,
      apiAvailable: true,
    );
  }

  static const empty = OrderDashboardData(
    totalOrders: 0,
    pendingOrders: 0,
    dispatchedOrders: 0,
    rejectedOrders: 0,
    recentOrders: [],
    apiAvailable: false,
  );

  static int? _asInt(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse('$value');
  }
}
