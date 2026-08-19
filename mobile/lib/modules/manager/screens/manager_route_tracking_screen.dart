import 'package:flutter/material.dart';

import '../../../core/api/api_client.dart';
import '../../../core/storage/session_store.dart';
import '../../auth/providers/auth_controller.dart';
import '../../director/api/director_api.dart';
import '../../director/screens/director_route_tracking_screen.dart';
import '../api/manager_api.dart';

/// Manager team-scoped route tracking — reuses Director route UI.
class ManagerRouteTrackingScreen extends StatelessWidget {
  const ManagerRouteTrackingScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    final api = ManagerApi(
      ApiClient(SessionStore(), onUnauthorized: auth.sessionExpired).dio,
    );

    return DirectorRouteTrackingScreen(
      auth: auth,
      title: 'Employee Route Tracking',
      detailPathPrefix: '/manager/route-tracking',
      listLoader: ({required String date}) async {
        final result = await api.listRouteTracking(date: date);
        return DirectorRouteTrackingListResult(
          rows: result.rows,
          meta: result.meta,
        );
      },
    );
  }
}

class ManagerRouteMapScreen extends StatelessWidget {
  const ManagerRouteMapScreen({
    super.key,
    required this.auth,
    required this.attendanceId,
  });

  final AuthController auth;
  final int attendanceId;

  @override
  Widget build(BuildContext context) {
    final api = ManagerApi(
      ApiClient(SessionStore(), onUnauthorized: auth.sessionExpired).dio,
    );

    return DirectorRouteMapScreen(
      auth: auth,
      attendanceId: attendanceId,
      loadRoute: api.getRouteTracking,
    );
  }
}
