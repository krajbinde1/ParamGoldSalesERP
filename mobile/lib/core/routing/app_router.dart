import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../auth/user_role.dart';
import 'route_permissions.dart';
import '../../modules/notifications/screens/notification_history_screen.dart';
import '../../modules/attendance/models/attendance.dart';
import '../../modules/attendance/screens/attendance_detail.dart';
import '../../modules/attendance/screens/attendance_history.dart';
import '../../modules/attendance/screens/attendance_home.dart';
import '../../modules/attendance/screens/punch_in_screen.dart';
import '../../modules/attendance/screens/punch_out_screen.dart';
import '../../modules/auth/providers/auth_controller.dart';
import '../../modules/auth/screens/change_password_screen.dart';
import '../../modules/auth/screens/login_screen.dart';
import '../../modules/auth/screens/splash_screen.dart';
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
import '../../modules/planning/screens/todays_planning_screen.dart';
import '../../modules/orders/models/order_draft.dart';
import '../../modules/orders/models/order_filter.dart';
import '../../modules/orders/screens/new_order_screen.dart';
import '../../modules/orders/screens/order_dashboard_screen.dart';
import '../../modules/orders/screens/order_detail_screen.dart';
import '../../modules/orders/screens/order_list_screen.dart';
import '../../modules/orders/screens/review_order_screen.dart';
import '../../modules/director/screens/director_dashboard_screen.dart';
import '../../modules/director/screens/director_payment_requests_screen.dart';
import '../../modules/director/screens/director_route_tracking_screen.dart';
import '../../modules/manager/screens/manager_edit_order_screen.dart';
import '../../modules/manager/screens/manager_employee_performance_screen.dart';
import '../../modules/manager/screens/manager_orders_screen.dart';
import '../../modules/manager/screens/manager_ta_da_screen.dart';
import '../../modules/manager/screens/manager_targets_screen.dart';
import '../../modules/manager/screens/manager_team_activity_screen.dart';
import '../../modules/manager/screens/manager_team_attendance_screen.dart';
import '../../modules/production/screens/production_dashboard_screen.dart';
import '../../modules/production/screens/production_order_detail_screen.dart';
import '../../modules/production/screens/production_status_orders_screen.dart';
import '../../modules/production/screens/inventory/inventory_dashboard_screen.dart';
import '../../modules/production/screens/inventory/inventory_hub_screens.dart';
import '../../modules/production/screens/inventory/raw_material_form_screen.dart';
import '../../modules/production/screens/inventory/raw_material_master_screen.dart';
import '../../modules/production/screens/inventory/stock_list_screen.dart';
import '../../modules/production/screens/inventory/production_batches_screen.dart';
import '../../modules/production/screens/inventory/production_batch_detail_screen.dart';
import '../../modules/production/screens/inventory/bom_shortage_history_screens.dart';
import '../../modules/production/screens/inventory/bom_screens.dart';
import '../../modules/production/screens/inventory/stock_report_screens.dart';
import '../../modules/production/screens/inventory/stock_item_ledger_screen.dart';
import '../../modules/production/screens/inventory/stock_ledger_browse_screen.dart';
import '../../modules/production/screens/inventory/raw_material_inward_screens.dart';
import '../../modules/production/screens/inventory/packaging_material_inward_screens.dart';
import '../../modules/production/screens/production_entry/production_entry_wizard_screen.dart';
import '../../modules/profile/screens/profile_screen.dart';
import '../notifications/critical_approval_alert_screen.dart';
import '../notifications/notification_payload.dart';

GoRouter createRouter(
  AuthController auth, {
  GlobalKey<NavigatorState>? navigatorKey,
}) => GoRouter(
  navigatorKey: navigatorKey,
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
      builder: (_, _) => SplashScreen(auth: auth),
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
      path: '/critical-approval-alert',
      builder: (_, state) {
        final extra = state.extra;
        final payload = extra is NotificationPayload
            ? extra
            : NotificationPayload.fromJson(
                extra is Map
                    ? Map<String, dynamic>.from(extra)
                    : <String, dynamic>{
                        'type': 'pending',
                        'title': 'Critical Alert',
                        'body': '',
                      },
              );
        return CriticalApprovalAlertScreen(payload: payload, auth: auth);
      },
    ),
    GoRoute(
      path: '/dashboard',
      builder: (_, _) => RoleDashboardScreen(auth: auth),
    ),
    GoRoute(
      path: '/planning',
      builder: (_, _) => TodaysPlanningScreen(auth: auth),
    ),
    GoRoute(
      path: '/profile',
      builder: (_, _) => ProfileScreen(auth: auth),
    ),
    GoRoute(
      path: '/notifications',
      builder: (_, _) => NotificationHistoryScreen(auth: auth),
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
      builder: (_, state) => ManagerOrdersScreen(
        auth: auth,
        initialTab: state.uri.queryParameters['tab'] ?? 'pending',
      ),
      routes: [
        GoRoute(
          path: ':orderId',
          builder: (_, state) => ManagerOrderDetailScreen(
            auth: auth,
            orderId: int.parse(state.pathParameters['orderId']!),
            initialAction: state.uri.queryParameters['action'],
          ),
          routes: [
            GoRoute(
              path: 'edit',
              builder: (_, state) => ManagerEditOrderScreen(
                auth: auth,
                orderId: int.parse(state.pathParameters['orderId']!),
                order: Map<String, dynamic>.from(state.extra! as Map),
              ),
            ),
          ],
        ),
      ],
    ),
    GoRoute(
      path: '/manager/team-attendance',
      builder: (_, _) => ManagerTeamAttendanceScreen(auth: auth),
      routes: [
        GoRoute(
          path: 'employees/:employeeId',
          builder: (_, state) => ManagerEmployeeAttendanceScreen(
            auth: auth,
            employeeId: int.parse(state.pathParameters['employeeId']!),
            initialDate: state.uri.queryParameters['date'],
          ),
        ),
        GoRoute(
          path: ':attendanceId',
          builder: (_, state) => ManagerTeamAttendanceDetailScreen(
            auth: auth,
            attendanceId: int.parse(state.pathParameters['attendanceId']!),
          ),
          routes: [
            GoRoute(
              path: 'route',
              builder: (_, state) => ManagerTeamRouteMapScreen(
                auth: auth,
                attendanceId: int.parse(state.pathParameters['attendanceId']!),
              ),
            ),
          ],
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
      path: '/manager/targets',
      builder: (_, _) => ManagerTargetsScreen(auth: auth),
    ),
    GoRoute(
      path: '/manager/team-activity',
      builder: (_, _) => ManagerTeamActivityScreen(auth: auth),
      routes: [
        GoRoute(
          path: 'employees/:employeeId',
          builder: (_, state) {
            final extra = state.extra as Map?;
            return ManagerEmployeeTeamActivityScreen(
              auth: auth,
              employeeId: int.parse(state.pathParameters['employeeId']!),
              date: extra?['date']?.toString() ??
                  DateTime.now().toIso8601String().substring(0, 10),
              type: extra?['type']?.toString() ?? 'all',
              employeeName: extra?['name']?.toString(),
              employeeCode: extra?['code']?.toString(),
            );
          },
        ),
      ],
    ),
    GoRoute(
      path: '/production/orders',
      builder: (_, _) {
        // TEMP DEBUG — alternate route entry (same screen class).
        // ignore: avoid_print
        print(
          '[PS ApprovedOrders DEBUG] GoRoute /production/orders → '
          'ProductionOrdersScreen (production_dashboard_screen.dart)',
        );
        return ProductionOrdersScreen(auth: auth);
      },
      routes: [
        GoRoute(
          path: 'by-status/:status',
          builder: (_, state) => ProductionStatusOrdersScreen(
            auth: auth,
            status: state.pathParameters['status'] ?? 'approved',
          ),
        ),
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
      path: '/production/inventory-manufacturing',
      builder: (_, _) => InventoryManufacturingHubScreen(auth: auth),
    ),
    GoRoute(
      path: '/production/material-masters',
      builder: (_, _) => MaterialMastersHubScreen(auth: auth),
    ),
    GoRoute(
      path: '/production/material-inward',
      builder: (_, _) => MaterialInwardHubScreen(auth: auth),
    ),
    GoRoute(
      path: '/production/production-hub',
      builder: (_, _) => ProductionModuleHubScreen(auth: auth),
    ),
    GoRoute(
      path: '/production/inventory',
      builder: (_, _) => InventoryDashboardScreen(auth: auth),
    ),
    GoRoute(
      path: '/production/raw-materials',
      builder: (_, _) => RawMaterialMasterScreen(auth: auth),
      routes: [
        GoRoute(
          path: 'create',
          builder: (_, _) => RawMaterialFormScreen(auth: auth),
        ),
        GoRoute(
          path: ':id/edit',
          builder: (_, state) => RawMaterialFormScreen(
            auth: auth,
            materialId: int.tryParse(state.pathParameters['id'] ?? ''),
          ),
        ),
      ],
    ),
    GoRoute(
      path: '/production/packaging-materials',
      builder: (_, _) =>
          StockListScreen(auth: auth, type: StockListType.packaging),
    ),
    GoRoute(
      path: '/production/semi-finished',
      builder: (_, _) =>
          StockListScreen(auth: auth, type: StockListType.semiFinished),
    ),
    GoRoute(
      path: '/production/finished-goods',
      builder: (_, _) =>
          StockListScreen(auth: auth, type: StockListType.finished),
    ),
    GoRoute(
      path: '/production/bom',
      builder: (_, _) => BomListScreen(auth: auth),
      routes: [
        GoRoute(
          path: ':bomId',
          builder: (_, state) => BomDetailScreen(
            auth: auth,
            bomId: int.parse(state.pathParameters['bomId']!),
          ),
        ),
      ],
    ),
    GoRoute(
      path: '/production/entry',
      builder: (_, _) => ProductionEntryWizardScreen(auth: auth),
    ),
    GoRoute(
      path: '/production/batches',
      builder: (_, _) => ProductionBatchesScreen(auth: auth),
      routes: [
        GoRoute(
          path: ':batchId',
          builder: (_, state) => ProductionBatchDetailScreen(
            auth: auth,
            batchId: int.parse(state.pathParameters['batchId']!),
          ),
        ),
      ],
    ),
    GoRoute(
      path: '/production/shortages',
      builder: (_, _) => MaterialShortageScreen(auth: auth),
    ),
    GoRoute(
      path: '/production/history',
      builder: (_, state) => ProductionHistoryScreen(
        auth: auth,
        initialFrom: state.uri.queryParameters['from'],
        initialTo: state.uri.queryParameters['to'],
      ),
    ),
    GoRoute(
      path: '/production/stock-report',
      builder: (_, state) => StockReportScreen(
        auth: auth,
        initialStatus: state.uri.queryParameters['status'],
        initialType: state.uri.queryParameters['type'],
      ),
    ),
    GoRoute(
      path: '/production/ledger',
      builder: (_, state) => StockItemLedgerScreen(
        auth: auth,
        itemType: state.uri.queryParameters['type'] ?? 'raw_material',
        itemId: int.tryParse(state.uri.queryParameters['id'] ?? '') ?? 0,
      ),
    ),
    GoRoute(
      path: '/production/stock-ledger',
      builder: (_, state) => StockLedgerBrowseScreen(
        auth: auth,
        initialItemType: state.uri.queryParameters['type'],
        initialTxnType: state.uri.queryParameters['txn'],
      ),
    ),
    GoRoute(
      path: '/production/inwards',
      builder: (_, _) => RawMaterialInwardHubScreen(auth: auth),
      routes: [
        GoRoute(
          path: 'new',
          builder: (_, _) => NewRawMaterialInwardScreen(auth: auth),
        ),
        GoRoute(
          path: ':inwardId',
          builder: (_, state) => RawMaterialInwardDetailScreen(
            auth: auth,
            inwardId: int.parse(state.pathParameters['inwardId']!),
          ),
        ),
      ],
    ),
    GoRoute(
      path: '/production/packaging-inwards',
      builder: (_, _) => PackagingMaterialInwardHubScreen(auth: auth),
      routes: [
        GoRoute(
          path: 'new',
          builder: (_, _) => NewPackagingMaterialInwardScreen(auth: auth),
        ),
        GoRoute(
          path: ':inwardId',
          builder: (_, state) => PackagingMaterialInwardDetailScreen(
            auth: auth,
            inwardId: int.parse(state.pathParameters['inwardId']!),
          ),
        ),
      ],
    ),
    GoRoute(
      path: '/director/employees',
      builder: (_, _) => DirectorEmployeePerformanceScreen(auth: auth),
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
      path: '/director/sales-performance',
      builder: (_, _) => DirectorEmployeePerformanceScreen(auth: auth),
    ),
    GoRoute(
      path: '/director/collections',
      builder: (_, _) => DirectorCollectionsScreen(auth: auth),
    ),
    GoRoute(
      path: '/director/team-activity',
      builder: (_, _) => DirectorTeamActivityScreen(auth: auth),
    ),
    GoRoute(
      path: '/director/route-tracking',
      builder: (_, _) => DirectorRouteTrackingScreen(auth: auth),
      routes: [
        GoRoute(
          path: ':attendanceId',
          builder: (_, state) => DirectorRouteMapScreen(
            auth: auth,
            attendanceId: int.parse(state.pathParameters['attendanceId']!),
          ),
        ),
      ],
    ),
    GoRoute(
      path: '/director/reports',
      builder: (_, _) => DirectorReportsScreen(auth: auth),
    ),
    GoRoute(
      path: '/director/orders',
      builder: (_, state) => DirectorOrdersScreen(
        auth: auth,
        initialStatus: state.uri.queryParameters['status'],
      ),
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
    GoRoute(
      path: '/director/payment-requests',
      builder: (_, state) => DirectorPaymentRequestsScreen(
        auth: auth,
        initialFilter: state.uri.queryParameters['filter'] ?? 'pending',
        selectAllOnLoad: state.uri.queryParameters['select_all'] == '1' ||
            state.uri.queryParameters['action'] == 'approve',
      ),
      routes: [
        GoRoute(
          path: ':requestId',
          builder: (_, state) {
            final rawId = state.pathParameters['requestId'] ?? '';
            final id = int.tryParse(rawId) ?? 0;
            return DirectorPaymentRequestDetailScreen(
              auth: auth,
              requestId: id,
            );
          },
        ),
      ],
    ),
  ],
);
