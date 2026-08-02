import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'models/route_point.dart';
import 'route_tracking_config.dart';
import 'route_tracking_service.dart';

class RouteTrackingStatusNotifier extends Notifier<RouteTrackingUiStatus> {
  @override
  RouteTrackingUiStatus build() => routeTrackingRuntimeEnabled
      ? RouteTrackingService.instance.uiStatus
      : RouteTrackingUiStatus.empty;

  Future<void> refresh() async {
    if (!routeTrackingRuntimeEnabled) {
      state = RouteTrackingUiStatus.empty;
      return;
    }
    state = await RouteTrackingService.instance.refreshStatus();
  }
}

final routeTrackingStatusProvider =
    NotifierProvider<RouteTrackingStatusNotifier, RouteTrackingUiStatus>(
      RouteTrackingStatusNotifier.new,
    );

/// Convenience string for snackbars / legacy checks.
final routeTrackingStatusMessageProvider = Provider<String>((ref) {
  return ref.watch(routeTrackingStatusProvider).message;
});

Future<void> refreshRouteTrackingStatus(WidgetRef ref) async {
  await ref.read(routeTrackingStatusProvider.notifier).refresh();
}

Future<void> refreshRouteTrackingStatusFromRef(Ref ref) async {
  await ref.read(routeTrackingStatusProvider.notifier).refresh();
}
