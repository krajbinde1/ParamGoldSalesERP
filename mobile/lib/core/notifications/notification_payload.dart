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

  int get notificationId =>
      orderId ?? DateTime.now().millisecondsSinceEpoch.remainder(100000);

  String? get resolvedRoute {
    if (actionId == 'ignore') return null;
    if (route != null && route!.isNotEmpty) return route;
    if (orderId == null) return null;
    if (type == 'new_order') return '/manager/orders/$orderId';
    return '/orders/$orderId';
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
      orderNo: data['order_no']?.toString(),
      dealerName: data['dealer_name']?.toString(),
      salesPersonName: data['sales_person_name']?.toString(),
      statusLabel: data['status_label']?.toString(),
      route: data['route']?.toString(),
      billUrl: data['bill_url']?.toString(),
      timeline: data['timeline']?.toString(),
      fullscreen: data['fullscreen']?.toString() == '1' ||
          data['type']?.toString() == 'new_order',
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
      orderNo: data['order_no']?.toString(),
      dealerName: data['dealer_name']?.toString(),
      salesPersonName: data['sales_person_name']?.toString(),
      statusLabel: data['status_label']?.toString(),
      route: data['route']?.toString(),
      billUrl: data['bill_url']?.toString(),
      timeline: data['timeline']?.toString(),
      actionId: response.actionId,
      fullscreen: data['fullscreen']?.toString() == '1',
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
