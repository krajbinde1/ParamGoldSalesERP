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
import '../storage/device_id_store.dart';
import '../storage/session_store.dart';
import 'notification_api.dart';
import 'notification_payload.dart';

const _pendingRouteKey = 'pending_notification_route';
const _pendingBillUrlKey = 'pending_notification_bill_url';
const _pendingPayloadKey = 'pending_notification_payload_json';

/// Recently shown notification ids — avoid duplicate local posts for one event.
final Set<String> _recentlyShownKeys = <String>{};

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

  final data = message.data;
  final type = data['type']?.toString().trim().toLowerCase();
  final code = data['code']?.toString().trim().toUpperCase();
  if (type == 'session_replaced' || code == 'SESSION_REPLACED') {
    try {
      await SessionStore().clear();
    } catch (_) {}
    if (message.notification != null) return;
  }

  final payload = NotificationPayload.fromRemoteMessage(message);

  // Critical fullscreen events are sent data-only from the backend so we can
  // attach Android fullScreenIntent on a single local notification.
  // Non-critical notification+data messages are already shown by the OS —
  // do not duplicate them locally.
  if (message.notification != null && !payload.isCriticalApprovalAlert) {
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
  bool _tokenRefreshBound = false;
  void Function()? _onSessionReplaced;

  Stream<NotificationPayload> get taps => _taps.stream;
  bool get isFirebaseReady => _firebaseReady;

  /// Called when FCM delivers a session_replaced event (best-effort fast logout).
  void setSessionReplacedHandler(void Function()? handler) {
    _onSessionReplaced = handler;
  }

  /// Shared plugin for local-only reminders (e.g. Today's Planning).
  FlutterLocalNotificationsPlugin get localPlugin => _local;

  /// Critical MAX channel for real Android system / heads-up alerts.
  /// New channel id (v3) because Android cannot raise importance of an
  /// existing channel after it was created at a lower level.
  static const AndroidNotificationChannel criticalAlertsChannel =
      AndroidNotificationChannel(
    'paramgold_critical_alerts_v3',
    'ParamGold Critical Alerts',
    description: 'Order and payment approval alerts with sound and vibration',
    importance: Importance.max,
    playSound: true,
    enableVibration: true,
    showBadge: true,
    enableLights: true,
  );

  /// Legacy channels kept so older scheduled/local notifications still resolve.
  static const AndroidNotificationChannel approvalsChannel =
      criticalAlertsChannel;

  static const AndroidNotificationChannel statusChannel = criticalAlertsChannel;

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
    await android?.createNotificationChannel(criticalAlertsChannel);
    await android?.createNotificationChannel(
      const AndroidNotificationChannel(
        'paramgold_approvals_v2',
        'ParamGold Approvals (legacy)',
        description: 'Legacy approvals channel',
        importance: Importance.max,
        playSound: true,
        enableVibration: true,
      ),
    );
    await android?.createNotificationChannel(
      const AndroidNotificationChannel(
        'paramgold_status_v2',
        'ParamGold Status (legacy)',
        description: 'Legacy status channel',
        importance: Importance.high,
        playSound: true,
        enableVibration: true,
      ),
    );

    _localReady = true;
  }

  Future<void> cancelNotification(int id) async {
    try {
      await ensureLocalInitialized();
      await _local.cancel(id: id);
    } catch (error) {
      debugPrint('cancelNotification failed: $error');
    }
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
      final granted = await android?.requestNotificationsPermission();
      debugPrint('Android POST_NOTIFICATIONS granted=$granted');
      final enabled = await android?.areNotificationsEnabled();
      debugPrint('Android notifications enabled=$enabled');
      try {
        // Android 14+: user may need to grant full-screen intent separately.
        await android?.requestFullScreenIntentPermission();
      } catch (error) {
        debugPrint('Full-screen intent permission request skipped: $error');
      }
    }
  }

  Future<void> _bindHandlers() async {
    if (!_firebaseReady || _handlersBound) return;
    _handlersBound = true;

    FirebaseMessaging.onMessage.listen((message) async {
      if (_isSessionReplacedMessage(message)) {
        _onSessionReplaced?.call();
        return;
      }

      final payload = NotificationPayload.fromRemoteMessage(message);
      // Always post a high-priority local notification (sound / vibration /
      // heads-up). Critical alerts also open the full-screen UI immediately.
      await showFromRemoteMessage(message);

      if (payload.isCriticalApprovalAlert) {
        _emitTap(payload);
      }
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
      final platform = Platform.isIOS ? 'ios' : 'android';
      final installationId = await DeviceIdStore().getOrCreate();
      await api.registerDeviceToken(
        token,
        platform: platform,
        installationId: installationId,
      );
      debugPrint('FCM registration result: success');

      if (!_tokenRefreshBound) {
        _tokenRefreshBound = true;
        FirebaseMessaging.instance.onTokenRefresh.listen((newToken) async {
          try {
            final refreshId = await DeviceIdStore().getOrCreate();
            await NotificationApi(
              ApiClient(
                store,
                onUnauthorized: onUnauthorized,
                clearSessionOnUnauthorized: false,
              ).dio,
            ).registerDeviceToken(
              newToken,
              platform: Platform.isIOS ? 'ios' : 'android',
              installationId: refreshId,
            );
          } catch (error) {
            debugPrint('FCM token refresh register failed: $error');
          }
        });
      }

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
    if (_isSessionReplacedMessage(message)) {
      _onSessionReplaced?.call();
      return;
    }
    await ensureLocalInitialized();
    final payload = NotificationPayload.fromRemoteMessage(message);
    if (payload.title.trim().isEmpty && payload.body.trim().isEmpty) {
      debugPrint('Skipping empty FCM notification payload');
      return;
    }

    final dedupeKey =
        '${payload.type}:${payload.orderId ?? payload.raw['payment_request_id'] ?? payload.notificationId}';
    if (_recentlyShownKeys.contains(dedupeKey)) {
      debugPrint('Skipping duplicate local notification for $dedupeKey');
      return;
    }
    _recentlyShownKeys.add(dedupeKey);
    Future<void>.delayed(const Duration(seconds: 8), () {
      _recentlyShownKeys.remove(dedupeKey);
    });

    final isCritical = payload.isCriticalApprovalAlert;
    final isPaymentApproval = payload.type == 'payment_approval_required' ||
        payload.type == 'payment_request_reminder' ||
        payload.type == 'payment_request_created' ||
        payload.type == 'payment_request_first_approved';
    final channel = criticalAlertsChannel;

    final androidDetails = AndroidNotificationDetails(
      channel.id,
      channel.name,
      channelDescription: channel.description,
      importance: Importance.max,
      priority: Priority.max,
      category: isCritical
          ? AndroidNotificationCategory.call
          : AndroidNotificationCategory.message,
      visibility: NotificationVisibility.public,
      playSound: true,
      enableVibration: true,
      enableLights: true,
      fullScreenIntent: isCritical,
      ticker: payload.title,
      icon: '@mipmap/ic_launcher',
      channelShowBadge: true,
      styleInformation: BigTextStyleInformation(
        payload.body,
        contentTitle: payload.title,
        summaryText: payload.orderNo ?? payload.requestNo,
      ),
      actions: isCritical
          ? <AndroidNotificationAction>[
              const AndroidNotificationAction(
                'ignore',
                'IGNORE',
                cancelNotification: true,
                showsUserInterface: false,
              ),
              if (isPaymentApproval) ...[
                const AndroidNotificationAction(
                  'view',
                  'VIEW',
                  showsUserInterface: true,
                ),
                const AndroidNotificationAction(
                  'approve',
                  'APPROVE',
                  showsUserInterface: true,
                ),
              ] else ...[
                AndroidNotificationAction(
                  payload.type == 'order_billed' ? 'view_bill' : 'review',
                  payload.type == 'order_billed'
                      ? 'VIEW BILL'
                      : payload.type == 'order_approved'
                          ? 'REVIEW ORDER'
                          : 'REVIEW',
                  showsUserInterface: true,
                ),
                if (payload.type == 'new_order')
                  const AndroidNotificationAction(
                    'reject',
                    'REJECT',
                    showsUserInterface: true,
                  ),
              ],
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
    await prefs.setString(_pendingPayloadKey, jsonEncode(payload.toJson()));
    if (payload.actionId == 'view_bill' &&
        (payload.billUrl?.isNotEmpty ?? false)) {
      await prefs.setString(_pendingBillUrlKey, payload.billUrl!);
    }
  }

  Future<NotificationPayload?> consumePendingNavigation() async {
    final prefs = await SharedPreferences.getInstance();
    final route = prefs.getString(_pendingRouteKey);
    final billUrl = prefs.getString(_pendingBillUrlKey);
    final payloadJson = prefs.getString(_pendingPayloadKey);
    await prefs.remove(_pendingRouteKey);
    await prefs.remove(_pendingBillUrlKey);
    await prefs.remove(_pendingPayloadKey);

    if (payloadJson != null && payloadJson.isNotEmpty) {
      try {
        final map = Map<String, dynamic>.from(jsonDecode(payloadJson) as Map);
        final payload = NotificationPayload.fromJson(map);
        if (payload.actionId == 'ignore') return null;
        return payload;
      } catch (_) {
        // Fall through to route-only pending.
      }
    }

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

  bool _isSessionReplacedMessage(RemoteMessage message) {
    final data = message.data;
    final type = data['type']?.toString().trim().toLowerCase();
    final code = data['code']?.toString().trim().toUpperCase();
    return type == 'session_replaced' || code == 'SESSION_REPLACED';
  }
}

@pragma('vm:entry-point')
void notificationActionBackground(NotificationResponse response) {
  // IGNORE should only dismiss — handled by cancelNotification: true.
  if (response.actionId == 'ignore') return;
}
