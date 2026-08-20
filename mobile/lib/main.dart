import 'dart:async';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'core/api/api_config.dart';
import 'core/design/app_theme.dart';
import 'core/design/material_icon_retention.dart';
import 'core/notifications/notification_navigator.dart';
import 'core/notifications/push_notification_service.dart';
import 'core/routing/app_router.dart';
import 'core/updates/app_update_controller.dart';
import 'modules/attendance/route_tracking/route_tracking_config.dart';
import 'modules/attendance/route_tracking/route_tracking_lifecycle.dart';
import 'modules/attendance/route_tracking/route_tracking_log.dart';
import 'modules/attendance/route_tracking/route_tracking_service.dart';
import 'modules/auth/providers/auth_controller.dart';

final authController = AuthController();
final appUpdateController = AppUpdateController();
final rootNavigatorKey = GlobalKey<NavigatorState>();

/// Built once per process start. After adding/changing routes, do a full
/// hot **restart** (not hot reload) so this instance is recreated.
final appRouter = createRouter(
  authController,
  appUpdateController,
  navigatorKey: rootNavigatorKey,
);

void main() {
  runZonedGuarded(() async {
    WidgetsFlutterBinding.ensureInitialized();

    // Keep Material icon glyphs alive for Flutter 3.44 release tree-shaking.
    // ignore: unnecessary_statements
    retainMaterialIconGlyphs();

    if (kDebugMode) {
      debugPrint('API base URL: ${ApiConfig.baseUrl}');
    }

    // Register FCM background handler early (no-op if Firebase not configured).
    try {
      FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);
    } catch (error) {
      debugPrint('FCM background handler registration skipped: $error');
    }

    // Create the critical Android notification channel as early as possible so
    // FCM system notifications (background/terminated) use Importance.MAX.
    unawaited(PushNotificationService.instance.ensureLocalInitialized());

    // Never block the first frame on route-tracking / native plugin setup.
    unawaited(_safeStartupHousekeeping());

    runApp(const ProviderScope(child: ParamGoldApp()));
  }, (error, stack) {
    debugPrint('Uncaught startup/runtime error: $error\n$stack');
    routeTrackingLog('Uncaught error: $error');
  });
}

Future<void> _safeStartupHousekeeping() async {
  try {
    if (!routeTrackingRuntimeEnabled) {
      await RouteTrackingService.instance
          .disableRuntimeCleanup()
          .timeout(const Duration(seconds: 5));
    }
  } catch (error, stackTrace) {
    debugPrint('Startup housekeeping failed: $error\n$stackTrace');
  }
}

class ParamGoldApp extends StatefulWidget {
  const ParamGoldApp({super.key});

  @override
  State<ParamGoldApp> createState() => _ParamGoldAppState();
}

class _ParamGoldAppState extends State<ParamGoldApp> with WidgetsBindingObserver {
  bool _returnedFromBackground = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      unawaited(
        NotificationNavigator.start(
          auth: authController,
          router: appRouter,
          navigatorKey: rootNavigatorKey,
        ),
      );
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.hidden) {
      _returnedFromBackground = true;
      return;
    }
    if (state != AppLifecycleState.resumed || !_returnedFromBackground) {
      return;
    }
    _returnedFromBackground = false;
    unawaited(appUpdateController.checkOnForegroundResume());
  }

  @override
  Widget build(BuildContext context) => RouteTrackingLifecycle(
    child: MaterialApp.router(
      title: 'ParamGold ERP',
      debugShowCheckedModeBanner: false,
      // Always Light Theme — ignore Android/iOS system Dark Mode.
      themeMode: ThemeMode.light,
      routerConfig: appRouter,
      theme: AppTheme.light(),
      darkTheme: AppTheme.light(),
      builder: (context, child) => Stack(
        children: [
          const MaterialIconRetention(),
          ?child,
        ],
      ),
    ),
  );
}
