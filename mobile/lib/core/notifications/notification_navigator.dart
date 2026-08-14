import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../storage/session_store.dart';
import '../utils/bill_document.dart';
import '../../modules/auth/providers/auth_controller.dart';
import 'notification_payload.dart';
import 'push_notification_service.dart';

/// Binds FCM tap events to GoRouter deep-links after authentication.
class NotificationNavigator {
  NotificationNavigator._();

  static StreamSubscription<NotificationPayload>? _sub;
  static bool _started = false;

  static Future<void> start({
    required AuthController auth,
    required GoRouter router,
    required GlobalKey<NavigatorState> navigatorKey,
  }) async {
    if (_started) return;
    _started = true;

    await PushNotificationService.instance.initialize();
    PushNotificationService.instance.setSessionReplacedHandler(() {
      auth.sessionReplaced();
    });

    _sub = PushNotificationService.instance.taps.listen((payload) {
      unawaited(_handle(auth, router, navigatorKey, payload));
    });

    auth.addListener(() {
      unawaited(_onAuthChanged(auth, router, navigatorKey));
    });

    if (auth.authenticated) {
      await _register(auth);
      await _consumePending(auth, router, navigatorKey);
    }
  }

  static Future<void> _onAuthChanged(
    AuthController auth,
    GoRouter router,
    GlobalKey<NavigatorState> navigatorKey,
  ) async {
    if (auth.authenticated) {
      await _register(auth);
      await _consumePending(auth, router, navigatorKey);
      return;
    }
  }

  static Future<void> _register(AuthController auth) async {
    // Fire-and-forget semantics for callers: failures are logged inside
    // PushNotificationService and must never log the user out.
    try {
      await PushNotificationService.instance.registerToken(
        store: SessionStore(),
        onUnauthorized: null,
      );
    } catch (error, stackTrace) {
      debugPrint('NotificationNavigator FCM register ignored: $error');
      debugPrintStack(stackTrace: stackTrace, label: 'NotificationNavigator._register');
    }
  }

  static Future<void> unregister(AuthController auth) async {
    await PushNotificationService.instance.unregisterToken(
      store: SessionStore(),
      onUnauthorized: null,
    );
  }

  static Future<void> _consumePending(
    AuthController auth,
    GoRouter router,
    GlobalKey<NavigatorState> navigatorKey,
  ) async {
    if (!auth.authenticated || auth.initializing) return;
    final pending =
        await PushNotificationService.instance.consumePendingNavigation();
    if (pending == null) return;
    await _handle(auth, router, navigatorKey, pending);
  }

  static Future<void> _handle(
    AuthController auth,
    GoRouter router,
    GlobalKey<NavigatorState> navigatorKey,
    NotificationPayload payload,
  ) async {
    if (!auth.authenticated) return;
    if (payload.actionId == 'ignore') return;

    if (payload.actionId == 'view_bill' &&
        (payload.billUrl?.isNotEmpty ?? false)) {
      final context = navigatorKey.currentContext;
      if (context != null && context.mounted) {
        await openBillDocument(context, url: payload.billUrl!);
      }
      return;
    }

    final route = payload.resolvedRoute;
    if (route == null || route.isEmpty) return;

    // Allow auth redirect to settle after cold start.
    await Future<void>.delayed(const Duration(milliseconds: 250));
    router.push(route);
  }

  static void dispose() {
    _sub?.cancel();
    _sub = null;
    _started = false;
  }
}
