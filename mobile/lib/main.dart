import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'core/api/api_config.dart';
import 'core/design/app_theme.dart';
import 'core/design/material_icon_retention.dart';
import 'core/routing/app_router.dart';
import 'modules/attendance/route_tracking/route_tracking_config.dart';
import 'modules/attendance/route_tracking/route_tracking_lifecycle.dart';
import 'modules/attendance/route_tracking/route_tracking_log.dart';
import 'modules/attendance/route_tracking/route_tracking_service.dart';
import 'modules/auth/providers/auth_controller.dart';

final authController = AuthController();
final appRouter = createRouter(authController);

void main() {
  runZonedGuarded(() async {
    WidgetsFlutterBinding.ensureInitialized();

    // Keep Material icon glyphs alive for Flutter 3.44 release tree-shaking.
    // ignore: unnecessary_statements
    retainMaterialIconGlyphs();

    if (kDebugMode) {
      debugPrint('API base URL: ${ApiConfig.baseUrl}');
    }

    // Never block the first frame on route-tracking / native plugin setup.
    // Heavy recovery or FGS must not run before Login/Dashboard is reachable.
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

class ParamGoldApp extends StatelessWidget {
  const ParamGoldApp({super.key});
  @override
  Widget build(BuildContext context) => RouteTrackingLifecycle(
    child: MaterialApp.router(
      title: 'ParamGold ERP',
      debugShowCheckedModeBanner: false,
      themeMode: ThemeMode.system,
      routerConfig: appRouter,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      builder: (context, child) => Stack(
        children: [
          // Keeps Material icon glyphs in release APKs (Flutter 3.44 tree-shake).
          const MaterialIconRetention(),
          ?child,
        ],
      ),
    ),
  );
}
