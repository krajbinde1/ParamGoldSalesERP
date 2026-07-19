import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../auth/user_role.dart';
import 'route_permissions.dart';
import '../../modules/attendance/models/attendance.dart';
import '../../modules/attendance/screens/attendance_detail.dart';
import '../../modules/attendance/screens/attendance_history.dart';
import '../../modules/attendance/screens/attendance_home.dart';
import '../../modules/attendance/screens/punch_in_screen.dart';
import '../../modules/attendance/screens/punch_out_screen.dart';
import '../../modules/auth/providers/auth_controller.dart';
import '../../modules/auth/screens/change_password_screen.dart';
import '../../modules/auth/screens/login_screen.dart';
import '../../modules/collections/screens/collection_dashboard_screen.dart';
import '../../modules/collections/screens/collection_detail_screen.dart';
import '../../modules/collections/screens/new_collection_screen.dart';
import '../../modules/ta_da_claims/screens/new_ta_da_claim_screen.dart';
import '../../modules/ta_da_claims/screens/ta_da_claim_dashboard_screen.dart';
import '../../modules/ta_da_claims/screens/ta_da_claim_detail_screen.dart';
import '../../modules/dealer_visits/screens/dealer_visit_dashboard_screen.dart';
import '../../modules/dealer_visits/screens/dealer_visit_detail_screen.dart';
import '../../modules/dealer_visits/screens/new_dealer_visit_screen.dart';
import '../../modules/field_activities/screens/field_activity_dashboard_screen.dart';
import '../../modules/field_activities/screens/field_activity_detail_screen.dart';
import '../../modules/field_activities/screens/new_field_activity_screen.dart';
import '../../modules/dashboard/screens/coming_soon_screen.dart';
import '../../modules/dashboard/screens/role_dashboard_screen.dart';
import '../../modules/orders/models/order_draft.dart';
import '../../modules/orders/models/order_filter.dart';
import '../../modules/orders/screens/new_order_screen.dart';
import '../../modules/orders/screens/order_dashboard_screen.dart';
import '../../modules/orders/screens/order_detail_screen.dart';
import '../../modules/orders/screens/order_list_screen.dart';
import '../../modules/orders/screens/review_order_screen.dart';
import '../../modules/director/screens/director_dashboard_screen.dart';
import '../../modules/manager/screens/manager_employee_performance_screen.dart';
import '../../modules/manager/screens/manager_orders_screen.dart';
import '../../modules/manager/screens/manager_ta_da_screen.dart';
import '../../modules/production/screens/production_dashboard_screen.dart';
import '../../modules/production/screens/production_order_detail_screen.dart';
import '../../modules/profile/screens/profile_screen.dart';

GoRouter createRouter(AuthController auth) => GoRouter(
  initialLocation: '/dashboard',
  refreshListenable: auth,
  redirect: (_, state) {
    final location = state.matchedLocation;
    if (auth.initializing) return location == '/splash' ? null : '/splash';
    if (!auth.authenticated) return location == '/login' ? null : '/login';
    if (auth.mustChangePassword) {
      return location == '/change-password' ? null : '/change-password';
    }
    if (location == '/login' ||
        location == '/change-password' ||
        location == '/splash') {
      return '/dashboard';
    }

    final role = UserRole.fromValue(auth.session?.user.role);
    if (!RoutePermissions.canAccessPath(location, role)) {
      return '/dashboard';
    }

    return null;
  },
  routes: [
    GoRoute(
      path: '/splash',
      builder: (_, _) =>
          const Scaffold(body: Center(child: CircularProgressIndicator())),
    ),
    GoRoute(
      path: '/login',
      builder: (_, _) => LoginScreen(auth: auth),
    ),
    GoRoute(
      path: '/change-password',
      builder: (_, _) => ChangePasswordScreen(auth: auth),
    ),
    GoRoute(
      path: '/dashboard',
      builder: (_, _) => RoleDashboardScreen(auth: auth),
    ),
    GoRoute(
      path: '/profile',
      builder: (_, _) => ProfileScreen(auth: auth),
    ),
    GoRoute(
      path: '/coming/:module',
      builder: (_, state) =>
          ComingSoonScreen(module: state.pathParameters['module']!),
    ),
    GoRoute(
      path: '/orders',
      builder: (_, _) => OrderDashboardScreen(auth: auth),
      routes: [
        GoRoute(
          path: 'list/:filter',
          builder: (_, state) {
            final filter =
                OrderFilterX.fromName(state.pathParameters['filter']) ??
                OrderFilter.all;
            return OrderListScreen(filter: filter, auth: auth);
          },
        ),
        GoRoute(
          path: 'new',
          builder: (_, _) => const NewOrderScreen(),
          routes: [
            GoRoute(
              path: 'review',
              builder: (_, state) => ReviewOrderScreen(
                draft: state.extra! as OrderDraft,
                auth: auth,
              ),
            ),
          ],
        ),
        GoRoute(
          path: ':orderId',
          builder: (_, state) => OrderDetailScreen(
            orderId: int.parse(state.pathParameters['orderId']!),
            auth: auth,
          ),
          routes: [
            GoRoute(
              path: 'edit',
              builder: (_, state) =>
                  NewOrderScreen(initialDraft: state.extra as OrderDraft?),
              routes: [
                GoRoute(
                  path: 'review',
                  builder: (_, state) => ReviewOrderScreen(
                    draft: state.extra! as OrderDraft,
                    auth: auth,
                  ),
                ),
              ],
            ),
          ],
        ),
      ],
    ),
    GoRoute(
      path: '/collections',
      builder: (_, _) => CollectionDashboardScreen(auth: auth),
      routes: [
        GoRoute(
          path: 'new',
          builder: (_, _) => NewCollectionScreen(auth: auth),
        ),
        GoRoute(
          path: ':collectionId',
          builder: (_, state) => CollectionDetailScreen(
            collectionId: int.parse(state.pathParameters['collectionId']!),
            auth: auth,
          ),
        ),
      ],
    ),
    GoRoute(
      path: '/field-activities',
      builder: (_, _) => FieldActivityDashboardScreen(auth: auth),
      routes: [
        GoRoute(
          path: 'new',
          builder: (_, _) => NewFieldActivityScreen(auth: auth),
        ),
        GoRoute(
          path: ':activityId',
          builder: (_, state) => FieldActivityDetailScreen(
            activityId: int.parse(state.pathParameters['activityId']!),
            auth: auth,
          ),
        ),
      ],
    ),
    GoRoute(
      path: '/dealer-visits',
      builder: (_, _) => DealerVisitDashboardScreen(auth: auth),
      routes: [
        GoRoute(
          path: 'new',
          builder: (_, _) => NewDealerVisitScreen(auth: auth),
        ),
        GoRoute(
          path: ':visitId',
          builder: (_, state) => DealerVisitDetailScreen(
            visitId: int.parse(state.pathParameters['visitId']!),
            auth: auth,
          ),
        ),
      ],
    ),
    GoRoute(
      path: '/ta-da-claims',
      builder: (_, _) => TaDaClaimDashboardScreen(auth: auth),
      routes: [
        GoRoute(
          path: 'new',
          builder: (_, state) => NewTaDaClaimScreen(
            auth: auth,
            initialClaimDate: state.extra is DateTime
                ? state.extra as DateTime
                : null,
          ),
        ),
        GoRoute(
          path: ':claimId',
          builder: (_, state) => TaDaClaimDetailScreen(
            claimId: int.parse(state.pathParameters['claimId']!),
            auth: auth,
          ),
        ),
      ],
    ),
    GoRoute(path: '/attendance', builder: (_, _) => const AttendanceHome()),
    GoRoute(
      path: '/attendance/punch-in',
      builder: (_, _) => const PunchInScreen(),
    ),
    GoRoute(
      path: '/attendance/punch-out',
      builder: (_, _) => const PunchOutScreen(),
    ),
    GoRoute(
      path: '/attendance/history',
      builder: (_, _) => const AttendanceHistory(),
    ),
    GoRoute(
      path: '/attendance/detail',
      builder: (_, state) =>
          AttendanceDetail(attendance: state.extra! as Attendance),
    ),
    GoRoute(
      path: '/manager/orders',
      builder: (_, _) => ManagerOrdersScreen(auth: auth),
      routes: [
        GoRoute(
          path: ':orderId',
          builder: (_, state) => ManagerOrderDetailScreen(
            auth: auth,
            orderId: int.parse(state.pathParameters['orderId']!),
          ),
        ),
      ],
    ),
    GoRoute(
      path: '/manager/ta-da-claims',
      builder: (_, _) => ManagerTaDaClaimsScreen(auth: auth),
      routes: [
        GoRoute(
          path: ':claimId',
          builder: (_, state) => ManagerTaDaClaimDetailScreen(
            auth: auth,
            claimId: int.parse(state.pathParameters['claimId']!),
          ),
        ),
      ],
    ),
    GoRoute(
      path: '/manager/employees',
      builder: (_, _) => ManagerEmployeePerformanceScreen(auth: auth),
      routes: [
        GoRoute(
          path: ':employeeId',
          builder: (_, state) {
            final extra = state.extra as Map?;
            return ManagerEmployeeDetailScreen(
              auth: auth,
              employeeId: int.parse(state.pathParameters['employeeId']!),
              period: extra?['period']?.toString() ?? 'month',
              startDate: extra?['startDate']?.toString(),
              endDate: extra?['endDate']?.toString(),
            );
          },
        ),
      ],
    ),
    GoRoute(
      path: '/production/orders',
      builder: (_, _) => ProductionOrdersScreen(auth: auth),
      routes: [
        GoRoute(
          path: ':orderId',
          builder: (_, state) => ProductionOrderDetailScreen(
            auth: auth,
            orderId: int.parse(state.pathParameters['orderId']!),
          ),
        ),
      ],
    ),
    GoRoute(
      path: '/director/employees',
      builder: (_, state) => DirectorEmployeePerformanceScreen(
        auth: auth,
        employees: (state.extra as List?)
                ?.map((item) => Map<String, dynamic>.from(item as Map))
                .toList() ??
            const [],
      ),
      routes: [
        GoRoute(
          path: ':employeeId',
          builder: (_, state) => DirectorEmployeeDetailScreen(
            auth: auth,
            employee: Map<String, dynamic>.from(
              state.extra! as Map,
            ),
          ),
        ),
      ],
    ),
    GoRoute(
      path: '/director/orders',
      builder: (_, _) => DirectorOrdersScreen(auth: auth),
      routes: [
        GoRoute(
          path: ':orderId',
          builder: (_, state) => DirectorOrderDetailScreen(
            auth: auth,
            orderId: int.parse(state.pathParameters['orderId']!),
          ),
        ),
      ],
    ),
    GoRoute(
      path: '/director/ta-da-claims',
      builder: (_, _) => DirectorTaDaClaimsScreen(auth: auth),
    ),
  ],
);
