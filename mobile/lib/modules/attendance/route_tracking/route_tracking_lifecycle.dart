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
    if (routeTrackingRuntimeEnabled && !_recoveryScheduled) {
      _recoveryScheduled = true;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        unawaited(RouteTrackingService.instance.recoverOnAppStart());
      });
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
      unawaited(RouteTrackingService.instance.onAppResumed());
    }
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
