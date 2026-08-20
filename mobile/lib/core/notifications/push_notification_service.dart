import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
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
const _criticalChannelName = 'paramgold/critical_alerts';

/// Recently shown notification ids — avoid duplicate local posts for one event.
final Set<String> _recentlyShownKeys = <String>{};

/// Top-level FCM background handler (must be a top-level / static function).
@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  WidgetsFlutterBinding.ensureInitialized();
  debugPrint('BACKGROUND_HANDLER_CALLED=flutter_dart');
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
  debugPrint(
    'FCM_CRITICAL_RECEIVED=${payload.isCriticalApprovalAlert} type=${payload.type}',
  );

  // Critical alerts on Android are posted by ParamGoldFcmReceiver with a
  // real fullScreenIntent → CriticalAlertActivity. Do not duplicate here.
  if (Platform.isAndroid && payload.isCriticalApprovalAlert) {
    debugPrint('FULL_SCREEN_INTENT_REQUESTED=false (native receiver owns critical)');
    return;
  }

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
  static const MethodChannel _criticalChannel = MethodChannel(_criticalChannelName);

  bool _firebaseReady = false;
  bool _localReady = false;
  bool _handlersBound = false;
  bool _tokenRefreshBound = false;
  void Function()? _onSessionReplaced;

  Stream<NotificationPayload> get taps => _taps.stream;
  bool get isFirebaseReady => _firebaseReady;

  void setSessionReplacedHandler(void Function()? handler) {
    _onSessionReplaced = handler;
  }

  FlutterLocalNotificationsPlugin get localPlugin => _local;

  /// v4: ringtone-style MAX/HIGH channel created for native FSI reliability.
  static const AndroidNotificationChannel criticalAlertsChannel =
      AndroidNotificationChannel(
    'paramgold_critical_alerts_v5',
    'ParamGold Critical Alerts',
    description: 'Full-screen order and payment approval alerts',
    importance: Importance.max,
    playSound: true,
    enableVibration: true,
    showBadge: true,
    enableLights: true,
    // Ringtone-style audio attributes match the native v5 channel.
    audioAttributesUsage: AudioAttributesUsage.notificationRingtone,
  );

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

    await ensureLocalInitialized();
    if (!_firebaseReady) return;

    await _requestPermissions();
    await _logCriticalDiagnostics();
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

  Future<Map<String, dynamic>?> getCriticalChannelInfo() async {
    if (!Platform.isAndroid) return null;
    try {
      final raw = await _criticalChannel.invokeMethod<dynamic>('getCriticalChannelInfo');
      if (raw is Map) {
        return Map<String, dynamic>.from(raw);
      }
    } catch (error) {
      debugPrint('getCriticalChannelInfo failed: $error');
    }
    return null;
  }

  Future<bool> canUseFullScreenIntent() async {
    if (!Platform.isAndroid) return false;
    try {
      final value =
          await _criticalChannel.invokeMethod<bool>('canUseFullScreenIntent');
      debugPrint('CAN_USE_FULL_SCREEN_INTENT=$value');
      return value ?? false;
    } catch (error) {
      debugPrint('canUseFullScreenIntent failed: $error');
      return false;
    }
  }

  Future<void> openFullScreenIntentSettings() async {
    if (!Platform.isAndroid) return;
    try {
      await _criticalChannel.invokeMethod<void>('openFullScreenIntentSettings');
    } catch (error) {
      debugPrint('openFullScreenIntentSettings failed: $error');
    }
  }

  /// Consume a launch that originated from native CriticalAlertActivity.
  Future<NotificationPayload?> consumeNativeCriticalLaunch() async {
    if (!Platform.isAndroid) return null;
    try {
      final raw =
          await _criticalChannel.invokeMethod<dynamic>('consumeNativeCriticalLaunch');
      if (raw is! Map) return null;
      final map = Map<String, dynamic>.from(raw);
      final payloadJson = map['payload_json']?.toString() ?? '';
      final action = map['action']?.toString();
      if (payloadJson.isNotEmpty) {
        final decoded =
            Map<String, dynamic>.from(jsonDecode(payloadJson) as Map);
        final payload = NotificationPayload.fromJson({
          ...decoded,
          'action_id': action,
          'open_critical_alert': action == null || action.isEmpty ? '1' : '0',
        });
        return payload;
      }
      final route = map['route']?.toString() ?? '';
      if (route.isEmpty && action != 'reject') return null;
      return NotificationPayload(
        type: map['type']?.toString() ?? 'pending',
        title: '',
        body: '',
        orderId: int.tryParse('${map['order_id'] ?? ''}'),
        route: route.isNotEmpty
            ? route
            : (action == 'reject' && map['order_id'] != null
                ? '/manager/orders/${map['order_id']}?action=reject'
                : null),
        actionId: action,
      );
    } catch (error) {
      debugPrint('consumeNativeCriticalLaunch failed: $error');
      return null;
    }
  }

  Future<void> _logCriticalDiagnostics() async {
    if (!Platform.isAndroid) return;
    final info = await getCriticalChannelInfo();
    if (info == null) return;
    debugPrint('CHANNEL_ID=${info['channelId']}');
    debugPrint('CHANNEL_IMPORTANCE=${info['importance']}');
    debugPrint('CAN_USE_FULL_SCREEN_INTENT=${info['canUseFullScreenIntent']}');
    debugPrint('targetSdk=${info['targetSdk']} sdkInt=${info['sdkInt']}');
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
      debugPrint('NOTIFICATION_PERMISSION=$granted');
      try {
        await android?.requestFullScreenIntentPermission();
      } catch (error) {
        debugPrint('Full-screen intent permission request skipped: $error');
      }
      final canUse = await canUseFullScreenIntent();
      if (!canUse) {
        debugPrint(
          'CAN_USE_FULL_SCREEN_INTENT=false — open Special app access → Full screen intents',
        );
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
      debugPrint(
        'FCM_CRITICAL_RECEIVED=${payload.isCriticalApprovalAlert} APP_STATE=foreground',
      );

      // Foreground: open Flutter critical alert UI. Native receiver skips FSI
      // while ParamGoldAppState.isInForeground=true.
      if (payload.isCriticalApprovalAlert) {
        await showFromRemoteMessage(message);
        _emitTap(payload);
        return;
      }

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

    final native = await consumeNativeCriticalLaunch();
    if (native != null) {
      await _storePending(native);
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
    final isPaymentApproval = payload.type == 'payment_approval' ||
        payload.type == 'payment_approval_reminder' ||
        payload.type == 'payment_approval_required' ||
        payload.type == 'payment_request_reminder' ||
        payload.type == 'payment_request_created' ||
        payload.type == 'payment_request_first_approved';
    final channel = criticalAlertsChannel;

    debugPrint('CHANNEL_ID=${channel.id}');
    debugPrint('FULL_SCREEN_INTENT_REQUESTED=$isCritical (flutter_local)');

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
      // Native CriticalAlertActivity owns true FSI for background/terminated.
      // Foreground still uses heads-up + Flutter alert screen.
      fullScreenIntent: false,
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
          : <AndroidNotificationAction>[
              AndroidNotificationAction(
                payload.type.startsWith('collection_') ? 'view' : 'view_order',
                payload.type.startsWith('collection_') ? 'VIEW' : 'VIEW ORDER',
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
      } catch (_) {}
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
  if (response.actionId == 'ignore') return;
}
