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
  bool _recoveryScheduled = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    // Recover an active punch-in session after cold start / process death.
    // Do NOT stop FGS here — that previously cleared tracking after swipe-away.
    if (routeTrackingRuntimeEnabled && !_recoveryScheduled) {
      _recoveryScheduled = true;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        unawaited(_runStartupRecovery());
      });
    }
  }

  Future<void> _runStartupRecovery() async {
    try {
      await RouteTrackingService.instance.recoverOnAppStart().timeout(
        const Duration(seconds: 12),
      );
    } catch (_) {
      // Keep UI responsive — recovery retries on resume.
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
    // Never stop the foreground service on pause/inactive/detached/hidden.
    // Tracking must continue until Punch Out.
    if (state == AppLifecycleState.resumed) {
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
