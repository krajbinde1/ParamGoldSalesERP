import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'core/api/api_config.dart';
import 'core/design/app_theme.dart';
import 'core/routing/app_router.dart';
import 'modules/attendance/route_tracking/route_tracking_config.dart';
import 'modules/attendance/route_tracking/route_tracking_lifecycle.dart';
import 'modules/attendance/route_tracking/route_tracking_service.dart';
import 'modules/auth/providers/auth_controller.dart';

final authController = AuthController();
final appRouter = createRouter(authController);

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  if (kDebugMode) {
    debugPrint('API base URL: ${ApiConfig.baseUrl}');
  }
  if (!routeTrackingRuntimeEnabled) {
    await RouteTrackingService.instance.disableRuntimeCleanup();
  }
  runApp(const ProviderScope(child: ParamGoldApp()));
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
    ),
  );
}
