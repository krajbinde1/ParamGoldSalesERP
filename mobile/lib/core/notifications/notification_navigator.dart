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
      final native =
          await PushNotificationService.instance.consumeNativeCriticalLaunch();
      if (native != null) {
        await _handle(auth, router, navigatorKey, native);
      }
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
      final native =
          await PushNotificationService.instance.consumeNativeCriticalLaunch();
      if (native != null) {
        await _handle(auth, router, navigatorKey, native);
      }
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
      debugPrintStack(
        stackTrace: stackTrace,
        label: 'NotificationNavigator._register',
      );
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

    // Allow auth redirect to settle after cold start.
    await Future<void>.delayed(const Duration(milliseconds: 250));

    // Critical approval: open full-screen alert (not generic dashboard).
    // Action shortcuts (review / view / approve / reject / view_bill) skip alert.
    final openAlert = payload.openCriticalAlert ||
        (payload.isCriticalApprovalAlert &&
            (payload.actionId == null || payload.actionId!.isEmpty) &&
            payload.type != 'pending');

    if (openAlert) {
      router.go('/critical-approval-alert', extra: payload);
      return;
    }

    if (payload.actionId == 'approve' &&
        (payload.type == 'payment_approval' ||
            payload.type == 'payment_approval_reminder' ||
            payload.type == 'payment_approval_required' ||
            payload.type == 'payment_request_reminder' ||
            payload.type == 'payment_request_created' ||
            payload.type == 'payment_request_first_approved')) {
      final id = payload.paymentRequestId;
      if (id != null) {
        router.go('/director/payment-requests/$id');
      } else {
        router.go(
          '/director/payment-requests?filter=pending&select_all=1&action=approve',
        );
      }
      return;
    }

    if (payload.actionId == 'view' ||
        payload.actionId == 'review' ||
        payload.actionId == 'view_payment_request') {
      if (payload.type.startsWith('payment_')) {
        final review = payload.reviewRoute;
        if (review != null && review.isNotEmpty) {
          router.go(review);
          return;
        }
        router.go('/director/payment-requests?filter=pending');
        return;
      }
    }

    if (payload.actionId == 'view_bill' &&
        (payload.billUrl?.isNotEmpty ?? false)) {
      final context = navigatorKey.currentContext;
      if (context != null && context.mounted) {
        await openBillDocument(context, url: payload.billUrl!);
      }
      // Also open order detail when available.
      final review = payload.reviewRoute;
      if (review != null && review.isNotEmpty) {
        router.push(review);
      }
      return;
    }

    final route = payload.resolvedRoute;
    if (route == null || route.isEmpty) return;
    if (route == '/critical-approval-alert') {
      router.go(route, extra: payload);
      return;
    }
    router.push(route);
  }

  static void dispose() {
    _sub?.cancel();
    _sub = null;
    _started = false;
  }
}
