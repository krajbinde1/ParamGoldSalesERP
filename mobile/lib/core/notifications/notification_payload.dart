import 'dart:convert';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

class NotificationPayload {
  const NotificationPayload({
    required this.type,
    required this.title,
    required this.body,
    this.orderId,
    this.orderNo,
    this.dealerName,
    this.vendorName,
    this.salesPersonName,
    this.requestNo,
    this.amount,
    this.eventAt,
    this.statusLabel,
    this.route,
    this.billUrl,
    this.timeline,
    this.actionId,
    this.fullscreen = false,
    this.openCriticalAlert = false,
    this.raw = const {},
  });

  final String type;
  final String title;
  final String body;
  final int? orderId;
  final String? orderNo;
  final String? dealerName;
  final String? vendorName;
  final String? salesPersonName;
  final String? requestNo;
  final String? amount;
  final String? eventAt;
  final String? statusLabel;
  final String? route;
  final String? billUrl;
  final String? timeline;
  final String? actionId;
  final bool fullscreen;
  /// When true, navigator opens [CriticalApprovalAlertScreen] first.
  final bool openCriticalAlert;
  final Map<String, dynamic> raw;

  int get notificationId {
    if (orderId != null) return orderId!;
    final collectionId = int.tryParse('${raw['collection_id'] ?? ''}');
    if (collectionId != null) return 800000 + collectionId;
    final paymentId = int.tryParse('${raw['payment_request_id'] ?? ''}');
    if (paymentId != null) return 700000 + paymentId;
    return Object.hash(type, title, body).abs().remainder(100000);
  }

  /// Critical events that should use full-screen approval alert UI.
  bool get isCriticalApprovalAlert {
    if (!fullscreen) return false;
    return type == 'new_order' ||
        type == 'order_approved' ||
        type == 'order_billed' ||
        _isPaymentCriticalType(type);
  }

  int? get paymentRequestId =>
      int.tryParse('${raw['payment_request_id'] ?? ''}');

  /// Deep-link to the existing review/detail screen (not the alert).
  String? get reviewRoute {
    if (route != null && route!.isNotEmpty) return route;
    if (_isCollectionType(type)) {
      final collectionId = int.tryParse('${raw['collection_id'] ?? ''}');
      if (collectionId != null) return '/collections/$collectionId';
    }
    if (type == 'new_order' && orderId != null) {
      return '/manager/orders/$orderId';
    }
    if (type == 'order_approved' && orderId != null) {
      return '/production/orders/$orderId';
    }
    if (type == 'order_billed' && orderId != null) {
      return '/production/orders/$orderId';
    }
    if (_isPaymentCriticalType(type) || type.startsWith('payment_request_')) {
      final id = paymentRequestId;
      if (id != null) return '/director/payment-requests/$id';
      return '/director/payment-requests';
    }
    if (orderId != null) return '/orders/$orderId';
    return null;
  }

  String? get resolvedRoute {
    if (actionId == 'ignore') return null;

    // Notification action shortcuts skip the alert and go straight to work.
    if (actionId == 'reject' && orderId != null) {
      return '/manager/orders/$orderId?action=reject';
    }
    if (actionId == 'approve' && _isPaymentCriticalType(type)) {
      final id = paymentRequestId;
      if (id != null) return '/director/payment-requests/$id';
      return '/director/payment-requests?filter=pending&select_all=1&action=approve';
    }
    if (actionId == 'review' ||
        actionId == 'view' ||
        actionId == 'view_order' ||
        actionId == 'view_bill' ||
        actionId == 'view_payment_request') {
      if (_isPaymentCriticalType(type) || type.startsWith('payment_')) {
        return reviewRoute;
      }
      return reviewRoute;
    }

    if (openCriticalAlert || isCriticalApprovalAlert) {
      return '/critical-approval-alert';
    }

    if (route != null && route!.isNotEmpty) return route;
    return reviewRoute;
  }

  factory NotificationPayload.fromRemoteMessage(RemoteMessage message) {
    final data = Map<String, dynamic>.from(message.data);
    final notification = message.notification;
    final baseBody = notification?.body ?? data['body']?.toString() ?? '';
    final type = data['type']?.toString() ?? 'order_status';
    final fullscreen = _parseFullscreen(data, type);
    return NotificationPayload(
      type: type,
      title: notification?.title ??
          data['title']?.toString() ??
          'Order update',
      body: _composeBody(baseBody, data),
      orderId: int.tryParse('${data['order_id'] ?? ''}'),
      orderNo: data['short_order_no']?.toString() ??
          data['order_no']?.toString(),
      dealerName: data['dealer_name']?.toString(),
      vendorName: data['vendor_name']?.toString(),
      salesPersonName: data['sales_person_name']?.toString(),
      requestNo: data['request_no']?.toString() ??
          data['short_request_no']?.toString(),
      amount: data['amount']?.toString() ??
          data['pending_amount']?.toString() ??
          data['grand_total']?.toString(),
      eventAt: data['event_at']?.toString() ??
          data['order_date']?.toString() ??
          data['created_at']?.toString(),
      statusLabel: data['status_label']?.toString(),
      route: data['route']?.toString(),
      billUrl: data['bill_url']?.toString(),
      timeline: data['timeline']?.toString(),
      fullscreen: fullscreen,
      openCriticalAlert: fullscreen && _isCriticalType(type),
      raw: data,
    );
  }

  factory NotificationPayload.fromLocalResponse(
    NotificationResponse response,
  ) {
    Map<String, dynamic> data = {};
    final raw = response.payload;
    if (raw != null && raw.isNotEmpty) {
      try {
        data = Map<String, dynamic>.from(jsonDecode(raw) as Map);
      } catch (_) {
        data = {};
      }
    }

    final type = data['type']?.toString() ?? 'order_status';
    final fullscreen = _parseFullscreen(data, type);
    final actionId = response.actionId;
    final openAlert = fullscreen &&
        _isCriticalType(type) &&
        (actionId == null || actionId.isEmpty);

    return NotificationPayload(
      type: type,
      title: data['title']?.toString() ?? '',
      body: data['body']?.toString() ?? '',
      orderId: int.tryParse('${data['order_id'] ?? ''}'),
      orderNo: data['short_order_no']?.toString() ??
          data['order_no']?.toString(),
      dealerName: data['dealer_name']?.toString(),
      vendorName: data['vendor_name']?.toString(),
      salesPersonName: data['sales_person_name']?.toString(),
      requestNo: data['request_no']?.toString() ??
          data['short_request_no']?.toString(),
      amount: data['amount']?.toString() ??
          data['pending_amount']?.toString() ??
          data['grand_total']?.toString(),
      eventAt: data['event_at']?.toString() ??
          data['order_date']?.toString() ??
          data['created_at']?.toString(),
      statusLabel: data['status_label']?.toString(),
      route: data['route']?.toString(),
      billUrl: data['bill_url']?.toString(),
      timeline: data['timeline']?.toString(),
      actionId: actionId,
      fullscreen: fullscreen,
      openCriticalAlert: openAlert,
      raw: data,
    );
  }

  factory NotificationPayload.fromJson(Map<String, dynamic> data) {
    final type = data['type']?.toString() ?? 'order_status';
    final fullscreen = _parseFullscreen(data, type);
    return NotificationPayload(
      type: type,
      title: data['title']?.toString() ?? '',
      body: data['body']?.toString() ?? '',
      orderId: int.tryParse('${data['order_id'] ?? ''}'),
      orderNo: data['short_order_no']?.toString() ??
          data['order_no']?.toString(),
      dealerName: data['dealer_name']?.toString(),
      vendorName: data['vendor_name']?.toString(),
      salesPersonName: data['sales_person_name']?.toString(),
      requestNo: data['request_no']?.toString() ??
          data['short_request_no']?.toString(),
      amount: data['amount']?.toString() ??
          data['pending_amount']?.toString() ??
          data['grand_total']?.toString(),
      eventAt: data['event_at']?.toString() ??
          data['order_date']?.toString() ??
          data['created_at']?.toString(),
      statusLabel: data['status_label']?.toString(),
      route: data['route']?.toString(),
      billUrl: data['bill_url']?.toString(),
      timeline: data['timeline']?.toString(),
      actionId: data['action_id']?.toString(),
      fullscreen: fullscreen,
      openCriticalAlert: data['open_critical_alert']?.toString() == '1' ||
          (fullscreen && _isCriticalType(type)),
      raw: data,
    );
  }

  Map<String, dynamic> toJson() => {
        'type': type,
        'title': title,
        'body': body,
        'order_id': orderId,
        'order_no': orderNo,
        'short_order_no': orderNo,
        'dealer_name': dealerName,
        'vendor_name': vendorName,
        'sales_person_name': salesPersonName,
        'request_no': requestNo,
        'amount': amount,
        'event_at': eventAt,
        'status_label': statusLabel,
        'route': route,
        'bill_url': billUrl,
        'timeline': timeline,
        'fullscreen': fullscreen ? '1' : '0',
        'open_critical_alert': openCriticalAlert ? '1' : '0',
        if (actionId != null) 'action_id': actionId,
        ...raw,
      };

  static bool _isCriticalType(String type) {
    return type == 'new_order' ||
        type == 'order_approved' ||
        type == 'order_billed' ||
        _isPaymentCriticalType(type);
  }

  static bool _isPaymentCriticalType(String type) {
    return type == 'payment_approval' ||
        type == 'payment_approval_reminder' ||
        type == 'payment_approval_required' ||
        type == 'payment_request_reminder' ||
        type == 'payment_request_created' ||
        type == 'payment_request_first_approved';
  }

  static bool _isCollectionType(String type) {
    return type == 'collection_created' ||
        type == 'collection_received' ||
        type == 'collection_status_updated';
  }

  static bool _parseFullscreen(Map<String, dynamic> data, String type) {
    if (_isCollectionType(type)) return false;
    if (data['fullscreen']?.toString() == '1') return true;
    if (data['fullscreen']?.toString() == '0') return false;
    // Legacy defaults for critical types when flag omitted.
    return type == 'new_order' || _isPaymentCriticalType(type);
  }

  static String _composeBody(String base, Map<String, dynamic> data) {
    final timeline = data['timeline']?.toString();
    if (timeline == null || timeline.isEmpty) return base;
    if (base.contains('Order Placed')) return base;
    return '$base\n\n$timeline';
  }
}
