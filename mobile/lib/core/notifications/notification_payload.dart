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
    this.salesPersonName,
    this.statusLabel,
    this.route,
    this.billUrl,
    this.timeline,
    this.actionId,
    this.fullscreen = false,
    this.raw = const {},
  });

  final String type;
  final String title;
  final String body;
  final int? orderId;
  final String? orderNo;
  final String? dealerName;
  final String? salesPersonName;
  final String? statusLabel;
  final String? route;
  final String? billUrl;
  final String? timeline;
  final String? actionId;
  final bool fullscreen;
  final Map<String, dynamic> raw;

  int get notificationId {
    if (orderId != null) return orderId!;
    final paymentId = int.tryParse('${raw['payment_request_id'] ?? ''}');
    if (paymentId != null) return 700000 + paymentId;
    return Object.hash(type, title, body).abs().remainder(100000);
  }

  String? get resolvedRoute {
    if (actionId == 'ignore') return null;
    if (actionId == 'review' ||
        type == 'payment_approval_required' ||
        type == 'payment_request_reminder' ||
        type == 'payment_request_created' ||
        type == 'payment_request_first_approved') {
      return route?.isNotEmpty == true
          ? route
          : '/director/payment-requests';
    }
    if (route != null && route!.isNotEmpty) return route;
    if (orderId == null && raw['payment_request_id'] == null) return null;
    if (type == 'new_order') return '/manager/orders/$orderId';
    if (type.startsWith('payment_request_')) {
      final id = raw['payment_request_id'] ?? orderId;
      return '/director/payment-requests/$id';
    }
    if (orderId != null) return '/orders/$orderId';
    return null;
  }

  factory NotificationPayload.fromRemoteMessage(RemoteMessage message) {
    final data = Map<String, dynamic>.from(message.data);
    final notification = message.notification;
    final baseBody =
        notification?.body ?? data['body']?.toString() ?? '';
    return NotificationPayload(
      type: data['type']?.toString() ?? 'order_status',
      title: notification?.title ??
          data['title']?.toString() ??
          'Order update',
      body: _composeBody(baseBody, data),
      orderId: int.tryParse('${data['order_id'] ?? ''}'),
      orderNo: data['short_order_no']?.toString() ??
          data['order_no']?.toString(),
      dealerName: data['dealer_name']?.toString(),
      salesPersonName: data['sales_person_name']?.toString(),
      statusLabel: data['status_label']?.toString(),
      route: data['route']?.toString(),
      billUrl: data['bill_url']?.toString(),
      timeline: data['timeline']?.toString(),
      fullscreen: data['fullscreen']?.toString() == '1' ||
          data['type']?.toString() == 'new_order' ||
          data['type']?.toString() == 'payment_approval_required' ||
          data['type']?.toString() == 'payment_request_reminder' ||
          data['type']?.toString() == 'payment_request_created' ||
          data['type']?.toString() == 'payment_request_first_approved',
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

    return NotificationPayload(
      type: data['type']?.toString() ?? 'order_status',
      title: data['title']?.toString() ?? '',
      body: data['body']?.toString() ?? '',
      orderId: int.tryParse('${data['order_id'] ?? ''}'),
      orderNo: data['short_order_no']?.toString() ??
          data['order_no']?.toString(),
      dealerName: data['dealer_name']?.toString(),
      salesPersonName: data['sales_person_name']?.toString(),
      statusLabel: data['status_label']?.toString(),
      route: data['route']?.toString(),
      billUrl: data['bill_url']?.toString(),
      timeline: data['timeline']?.toString(),
      actionId: response.actionId,
      fullscreen: data['fullscreen']?.toString() == '1' ||
          data['type']?.toString() == 'payment_approval_required' ||
          data['type']?.toString() == 'payment_request_reminder',
      raw: data,
    );
  }

  Map<String, dynamic> toJson() => {
        'type': type,
        'title': title,
        'body': body,
        'order_id': orderId,
        'order_no': orderNo,
        'dealer_name': dealerName,
        'sales_person_name': salesPersonName,
        'status_label': statusLabel,
        'route': route,
        'bill_url': billUrl,
        'timeline': timeline,
        'fullscreen': fullscreen ? '1' : '0',
        ...raw,
      };

  static String _composeBody(String base, Map<String, dynamic> data) {
    final timeline = data['timeline']?.toString();
    if (timeline == null || timeline.isEmpty) return base;
    if (base.contains('Order Placed')) return base;
    return '$base\n\n$timeline';
  }
}
