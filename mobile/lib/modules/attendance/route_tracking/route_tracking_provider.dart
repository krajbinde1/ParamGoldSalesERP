import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'route_tracking_config.dart';
import 'route_tracking_service.dart';

class RouteTrackingStatusNotifier extends Notifier<String> {
  @override
  String build() => routeTrackingRuntimeEnabled
      ? RouteTrackingService.instance.statusMessage
      : '';

  void refresh() {
    state = routeTrackingRuntimeEnabled
        ? RouteTrackingService.instance.statusMessage
        : '';
  }
}

final routeTrackingStatusProvider =
    NotifierProvider<RouteTrackingStatusNotifier, String>(
      RouteTrackingStatusNotifier.new,
    );

Future<void> refreshRouteTrackingStatus(WidgetRef ref) async {
  ref.read(routeTrackingStatusProvider.notifier).refresh();
}

Future<void> refreshRouteTrackingStatusFromRef(Ref ref) async {
  ref.read(routeTrackingStatusProvider.notifier).refresh();
}
