import 'package:dio/dio.dart';
import '../api/api_errors.dart';

class NotificationApi {
  const NotificationApi(this._dio);
  final Dio _dio;

  Future<void> registerDeviceToken(
    String token, {
    String platform = 'android',
    String? deviceName,
  }) async {
    try {
      await _dio.post(
        '/device-tokens',
        data: {
          'token': token,
          'platform': platform,
          'device_name': ?deviceName,
        },
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<void> unregisterDeviceToken(String token) async {
    try {
      await _dio.delete(
        '/device-tokens',
        data: {'token': token},
      );
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<NotificationHistoryResult> listNotifications() async {
    try {
      final response = await _dio.get('/notifications');
      final body = Map<String, dynamic>.from(response.data as Map);
      return NotificationHistoryResult.fromJson(body);
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<void> markRead(int id) async {
    try {
      await _dio.post('/notifications/$id/read');
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }

  Future<void> markAllRead() async {
    try {
      await _dio.post('/notifications/read-all');
    } on DioException catch (error) {
      throw mapApiError(error);
    }
  }
}

class NotificationHistoryResult {
  const NotificationHistoryResult({
    required this.unreadCount,
    required this.items,
  });

  final int unreadCount;
  final List<AppNotificationItem> items;

  factory NotificationHistoryResult.fromJson(Map<String, dynamic> json) {
    return NotificationHistoryResult(
      unreadCount: int.tryParse('${json['unread_count'] ?? 0}') ?? 0,
      items: (json['data'] as List?)
              ?.map(
                (item) => AppNotificationItem.fromJson(
                  Map<String, dynamic>.from(item as Map),
                ),
              )
              .toList() ??
          const [],
    );
  }
}

class AppNotificationItem {
  const AppNotificationItem({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    required this.read,
    this.orderId,
    this.createdAt,
    this.data = const {},
  });

  final int id;
  final String type;
  final String title;
  final String body;
  final bool read;
  final int? orderId;
  final String? createdAt;
  final Map<String, dynamic> data;

  factory AppNotificationItem.fromJson(Map<String, dynamic> json) {
    return AppNotificationItem(
      id: int.tryParse('${json['id']}') ?? 0,
      type: json['type']?.toString() ?? '',
      title: json['title']?.toString() ?? '',
      body: (json['body'] ?? json['message'])?.toString() ?? '',
      read: json['read'] == true,
      orderId: int.tryParse('${json['order_id'] ?? ''}'),
      createdAt: json['created_at']?.toString(),
      data: Map<String, dynamic>.from(json['data'] as Map? ?? const {}),
    );
  }

  String? get route => data['route']?.toString();
  String? get billUrl => data['bill_url']?.toString();
}
