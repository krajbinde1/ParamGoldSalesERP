import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
import '../storage/session_store.dart';
import 'notification_api.dart';
import 'notification_payload.dart';

const _pendingRouteKey = 'pending_notification_route';
const _pendingBillUrlKey = 'pending_notification_bill_url';

/// Top-level FCM background handler (must be a top-level / static function).
@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  WidgetsFlutterBinding.ensureInitialized();
  try {
    if (Firebase.apps.isEmpty) {
      await Firebase.initializeApp();
    }
  } catch (_) {
    return;
  }

  final service = PushNotificationService.instance;
  await service.ensureLocalInitialized();
  await service.showFromRemoteMessage(message);
}

class PushNotificationService {
  PushNotificationService._();
  static final PushNotificationService instance = PushNotificationService._();

  final FlutterLocalNotificationsPlugin _local =
      FlutterLocalNotificationsPlugin();
  final StreamController<NotificationPayload> _taps =
      StreamController<NotificationPayload>.broadcast();

  bool _firebaseReady = false;
  bool _localReady = false;
  bool _handlersBound = false;

  Stream<NotificationPayload> get taps => _taps.stream;
  bool get isFirebaseReady => _firebaseReady;

  /// Shared plugin for local-only reminders (e.g. Today's Planning).
  FlutterLocalNotificationsPlugin get localPlugin => _local;

  static const AndroidNotificationChannel approvalsChannel =
      AndroidNotificationChannel(
    'order_approvals',
    'Order Approvals',
    description: 'High-priority new order alerts for managers',
    importance: Importance.max,
    playSound: true,
    enableVibration: true,
    showBadge: true,
  );

  static const AndroidNotificationChannel statusChannel =
      AndroidNotificationChannel(
    'order_status',
    'Order Status Updates',
    description: 'Order approved, billed, dispatched and rejected updates',
    importance: Importance.high,
    playSound: true,
    enableVibration: true,
    showBadge: true,
  );

  Future<void> initialize() async {
    if (kIsWeb) return;

    try {
      if (Firebase.apps.isEmpty) {
        await Firebase.initializeApp();
      }
      _firebaseReady = true;
    } catch (error, stack) {
      debugPrint('Firebase init skipped/failed: $error\n$stack');
      _firebaseReady = false;
    }

    // Local notifications must initialize even when FCM is unavailable
    // (used by Today's Planning reminders).
    await ensureLocalInitialized();
    if (!_firebaseReady) return;

    await _requestPermissions();
    await _bindHandlers();
  }

  Future<void> ensureLocalInitialized() async {
    if (_localReady) return;

    const androidInit = AndroidInitializationSettings('@mipmap/ic_launcher');
    const iosInit = DarwinInitializationSettings();
    await _local.initialize(
      settings: const InitializationSettings(android: androidInit, iOS: iosInit),
      onDidReceiveNotificationResponse: _onLocalResponse,
      onDidReceiveBackgroundNotificationResponse: notificationActionBackground,
    );

    final android = _local.resolvePlatformSpecificImplementation<
        AndroidFlutterLocalNotificationsPlugin>();
    await android?.createNotificationChannel(approvalsChannel);
    await android?.createNotificationChannel(statusChannel);

    _localReady = true;
  }

  Future<void> _requestPermissions() async {
    if (!_firebaseReady) return;

    final messaging = FirebaseMessaging.instance;
    await messaging.requestPermission(
      alert: true,
      badge: true,
      sound: true,
      announcement: false,
      carPlay: false,
      criticalAlert: false,
      provisional: false,
    );

    if (Platform.isAndroid) {
      final android = _local.resolvePlatformSpecificImplementation<
          AndroidFlutterLocalNotificationsPlugin>();
      await android?.requestNotificationsPermission();
    }
  }

  Future<void> _bindHandlers() async {
    if (!_firebaseReady || _handlersBound) return;
    _handlersBound = true;

    // Background handler is registered from main() before runApp.

    FirebaseMessaging.onMessage.listen((message) async {
      await showFromRemoteMessage(message);
    });

    FirebaseMessaging.onMessageOpenedApp.listen((message) {
      final payload = NotificationPayload.fromRemoteMessage(message);
      _emitTap(payload);
    });

    final initial = await FirebaseMessaging.instance.getInitialMessage();
    if (initial != null) {
      await _storePending(NotificationPayload.fromRemoteMessage(initial));
    }

    final launch = await _local.getNotificationAppLaunchDetails();
    if (launch?.didNotificationLaunchApp == true &&
        launch?.notificationResponse != null) {
      final payload = NotificationPayload.fromLocalResponse(
        launch!.notificationResponse!,
      );
      await _storePending(payload);
    }
  }

  Future<String?> registerToken({
    required SessionStore store,
    void Function()? onUnauthorized,
  }) async {
    debugPrint('FCM registration start (firebaseReady=$_firebaseReady)');
    if (!_firebaseReady) {
      debugPrint('FCM registration skipped: Firebase not ready');
      return null;
    }

    try {
      final token = await FirebaseMessaging.instance.getToken();
      if (token == null || token.isEmpty) {
        debugPrint('FCM registration result: no device token');
        return null;
      }

      // Never clear the auth session if device-token API returns 401.
      // Login must remain successful when notification registration fails.
      final api = NotificationApi(
        ApiClient(
          store,
          onUnauthorized: onUnauthorized,
          clearSessionOnUnauthorized: false,
        ).dio,
      );
      await api.registerDeviceToken(token);
      debugPrint('FCM registration result: success');

      FirebaseMessaging.instance.onTokenRefresh.listen((newToken) async {
        try {
          await NotificationApi(
            ApiClient(
              store,
              onUnauthorized: onUnauthorized,
              clearSessionOnUnauthorized: false,
            ).dio,
          ).registerDeviceToken(newToken);
        } catch (error) {
          debugPrint('FCM token refresh register failed: $error');
        }
      });

      return token;
    } catch (error, stackTrace) {
      debugPrint('FCM registration result: failed — $error');
      debugPrintStack(stackTrace: stackTrace, label: 'FCM.registerToken');
      return null;
    }
  }

  Future<void> unregisterToken({
    required SessionStore store,
    void Function()? onUnauthorized,
  }) async {
    if (!_firebaseReady) return;
    try {
      final token = await FirebaseMessaging.instance.getToken();
      if (token == null) return;
      await NotificationApi(
        ApiClient(store, onUnauthorized: onUnauthorized).dio,
      ).unregisterDeviceToken(token);
    } catch (error) {
      debugPrint('FCM token unregister failed: $error');
    }
  }

  Future<void> showFromRemoteMessage(RemoteMessage message) async {
    await ensureLocalInitialized();
    final payload = NotificationPayload.fromRemoteMessage(message);
    final isFullScreen = payload.fullscreen || payload.type == 'new_order';
    final channel =
        isFullScreen ? approvalsChannel : statusChannel;

    final androidDetails = AndroidNotificationDetails(
      channel.id,
      channel.name,
      channelDescription: channel.description,
      importance: Importance.max,
      priority: Priority.max,
      category: isFullScreen
          ? AndroidNotificationCategory.call
          : AndroidNotificationCategory.message,
      fullScreenIntent: isFullScreen,
      playSound: true,
      enableVibration: true,
      ticker: payload.title,
      styleInformation: BigTextStyleInformation(
        payload.body,
        contentTitle: payload.title,
        summaryText: payload.orderNo,
      ),
      actions: isFullScreen
          ? <AndroidNotificationAction>[
              const AndroidNotificationAction(
                'ignore',
                'IGNORE',
                cancelNotification: true,
                showsUserInterface: false,
              ),
              const AndroidNotificationAction(
                'review',
                'REVIEW & APPROVE',
                showsUserInterface: true,
              ),
            ]
          : payload.type == 'order_billed'
              ? <AndroidNotificationAction>[
                  const AndroidNotificationAction(
                    'view_order',
                    'VIEW ORDER',
                    showsUserInterface: true,
                  ),
                  const AndroidNotificationAction(
                    'view_bill',
                    'VIEW BILL',
                    showsUserInterface: true,
                  ),
                ]
              : <AndroidNotificationAction>[
                  const AndroidNotificationAction(
                    'view_order',
                    'VIEW ORDER',
                    showsUserInterface: true,
                  ),
                ],
    );

    await _local.show(
      id: payload.notificationId,
      title: payload.title,
      body: payload.body,
      notificationDetails: NotificationDetails(android: androidDetails),
      payload: jsonEncode(payload.toJson()),
    );
  }

  void _onLocalResponse(NotificationResponse response) {
    final payload = NotificationPayload.fromLocalResponse(response);
    if (payload.actionId == 'ignore') {
      return;
    }
    _emitTap(payload);
  }

  void _emitTap(NotificationPayload payload) {
    unawaited(_storePending(payload));
    _taps.add(payload);
  }

  Future<void> _storePending(NotificationPayload payload) async {
    if (payload.actionId == 'ignore') return;
    final prefs = await SharedPreferences.getInstance();
    final route = payload.resolvedRoute;
    if (route != null && route.isNotEmpty) {
      await prefs.setString(_pendingRouteKey, route);
    }
    if (payload.actionId == 'view_bill' &&
        (payload.billUrl?.isNotEmpty ?? false)) {
      await prefs.setString(_pendingBillUrlKey, payload.billUrl!);
    }
  }

  Future<NotificationPayload?> consumePendingNavigation() async {
    final prefs = await SharedPreferences.getInstance();
    final route = prefs.getString(_pendingRouteKey);
    final billUrl = prefs.getString(_pendingBillUrlKey);
    await prefs.remove(_pendingRouteKey);
    await prefs.remove(_pendingBillUrlKey);
    if (route == null || route.isEmpty) return null;
    return NotificationPayload(
      type: 'pending',
      title: '',
      body: '',
      route: route,
      billUrl: billUrl,
      actionId: billUrl != null ? 'view_bill' : 'view_order',
    );
  }
}

@pragma('vm:entry-point')
void notificationActionBackground(NotificationResponse response) {
  // IGNORE should only dismiss — handled by cancelNotification: true.
  if (response.actionId == 'ignore') return;
}
