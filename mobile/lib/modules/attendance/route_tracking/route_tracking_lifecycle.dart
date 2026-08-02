import 'dart:async';

import 'package:flutter/material.dart';

import 'route_tracking_config.dart';
import 'route_tracking_service.dart';

class RouteTrackingLifecycle extends StatefulWidget {
  const RouteTrackingLifecycle({super.key, required this.child});

  final Widget child;

  @override
  State<RouteTrackingLifecycle> createState() => _RouteTrackingLifecycleState();
}

class _RouteTrackingLifecycleState extends State<RouteTrackingLifecycle>
    with WidgetsBindingObserver {
  bool _startupGuardScheduled = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    // Do NOT recover/start GPS or FGS on launch. Only stop orphaned services
    // left by older builds (autoRunOnBoot). Tracking starts after punch-in.
    if (routeTrackingRuntimeEnabled && !_startupGuardScheduled) {
      _startupGuardScheduled = true;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        unawaited(_runColdStartGuard());
      });
    }
  }

  Future<void> _runColdStartGuard() async {
    try {
      await RouteTrackingService.instance.ensureStoppedOnColdStart().timeout(
        const Duration(seconds: 8),
      );
    } catch (_) {
      // Ignore housekeeping failures — UI must remain responsive.
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!routeTrackingRuntimeEnabled) return;
    if (state == AppLifecycleState.resumed) {
      // Soft sync only — never start engines from resume/server attendance.
      unawaited(
        RouteTrackingService.instance.onAppResumed().timeout(
          const Duration(seconds: 12),
          onTimeout: () {},
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
