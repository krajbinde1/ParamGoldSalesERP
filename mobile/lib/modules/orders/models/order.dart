class Order {
  const Order({
    this.id,
    required this.orderNo,
    required this.dealerName,
    required this.orderDate,
    required this.amount,
    required this.status,
  });

  final int? id;
  final String orderNo;
  final String dealerName;
  final DateTime orderDate;
  final double amount;
  final String status;

  /// Display-only short form, e.g. `PG-20260813-0004` → `PG-0004`.
  String get displayOrderNo => OrderNoDisplay.short(orderNo);

  factory Order.fromJson(Map<String, dynamic> json) {
    final dateRaw = json['order_date']?.toString() ?? '';
    return Order(
      id: int.tryParse('${json['id'] ?? ''}'),
      orderNo: json['order_no']?.toString() ?? '—',
      dealerName:
          json['dealer_name']?.toString() ??
          json['dealer']?['firm_name']?.toString() ??
          '—',
      orderDate: DateTime.tryParse(dateRaw) ?? DateTime.now(),
      amount:
          double.tryParse('${json['amount'] ?? json['grand_total'] ?? 0}') ?? 0,
      status: json['status']?.toString() ?? 'pending',
    );
  }
}

/// Display-only formatting for order numbers. Never mutate stored/API values.
abstract final class OrderNoDisplay {
  /// `PG-20260813-0004` → `PG-0004` (prefix + sequence).
  static String short(String orderNo) {
    final trimmed = orderNo.trim();
    if (trimmed.isEmpty || trimmed == '—') return trimmed;
    final parts = trimmed.split('-').where((p) => p.isNotEmpty).toList();
    if (parts.length >= 3) {
      return '${parts.first}-${parts.last}';
    }
    return trimmed;
  }
}

enum OrderBadgeTone { pending, approved, dispatched, rejected }

abstract final class OrderStatusRules {
  static String normalize(String status) =>
      status.trim().toLowerCase().replaceAll(' ', '_');

  static bool isPendingBucket(String status) {
    final value = normalize(status);
    return {
      'pending',
      'pending_approval',
      'approved',
      'processing',
      'draft',
    }.contains(value);
  }

  static bool isDispatchedBucket(String status) {
    final value = normalize(status);
    return {'dispatched', 'delivered'}.contains(value);
  }

  static bool isRejectedBucket(String status) {
    final value = normalize(status);
    return {'rejected', 'cancelled'}.contains(value);
  }

  static OrderBadgeTone badgeTone(String status) {
    final value = normalize(status);
    if (isRejectedBucket(value)) return OrderBadgeTone.rejected;
    if (value == 'dispatched' || value == 'delivered') {
      return OrderBadgeTone.dispatched;
    }
    if (value == 'approved' || value == 'billed') return OrderBadgeTone.approved;
    if (value == 'pending_for_billing' || value == 'sent_for_bill') {
      return OrderBadgeTone.pending;
    }
    return OrderBadgeTone.pending;
  }

  static String badgeLabel(String status, {String? rejectedByRole, String? statusLabel}) {
    if (statusLabel != null && statusLabel.trim().isNotEmpty) {
      return statusLabel.trim();
    }

    final value = normalize(status);
    if (value == 'rejected') {
      final role = (rejectedByRole ?? '').trim().toLowerCase();
      if (role.contains('admin')) return 'Rejected by Admin';
      if (role.contains('manager') || role.contains('sales')) {
        return 'Rejected by Sales Manager';
      }
      return 'Rejected';
    }

    return switch (value) {
      'draft' => 'Pending for Manager Approval',
      'pending_approval' => 'Pending for Manager Approval',
      'approved' => 'Approved by Sales Manager',
      'on_hold' => 'On Hold',
      'reverted_to_manager' => 'Returned by Production',
      'pending_for_billing' => 'Pending for Billing',
      'sent_for_bill' => 'Pending for Billing',
      'billed' => 'Billed',
      'processing' => 'Processing',
      'dispatched' => 'Dispatched',
      'delivered' => 'Delivered',
      'cancelled' => 'Cancelled',
      'pending' => 'Pending for Manager Approval',
      _ => status,
    };
  }
}
