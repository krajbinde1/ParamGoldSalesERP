import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'route_simulator.dart';
import 'route_tracking_debug_config.dart';

class RouteSimulatorNotifier extends Notifier<RouteSimulationProgress> {
  @override
  RouteSimulationProgress build() => RouteSimulator.instance.progress;

  Future<void> start(int attendanceId) async {
    if (!routeSimulationEnabled) return;
    state = const RouteSimulationProgress(
      status: RouteSimulationStatus.running,
      currentPoint: 0,
      message: 'Starting route simulation…',
    );
    await RouteSimulator.instance.start(
      attendanceId: attendanceId,
      onProgress: (progress) => state = progress,
    );
  }

  Future<void> stop() async {
    if (!routeSimulationEnabled) return;
    await RouteSimulator.instance.stop();
    state = RouteSimulator.instance.progress;
  }
}

final routeSimulatorProvider =
    NotifierProvider<RouteSimulatorNotifier, RouteSimulationProgress>(
      RouteSimulatorNotifier.new,
    );
