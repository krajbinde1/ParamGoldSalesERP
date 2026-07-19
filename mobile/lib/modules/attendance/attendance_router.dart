import 'package:go_router/go_router.dart';
import 'models/attendance.dart';
import 'screens/attendance_detail.dart';
import 'screens/attendance_history.dart';
import 'screens/attendance_home.dart';
import 'screens/punch_in_screen.dart';
import 'screens/punch_out_screen.dart';

final attendanceRouter = GoRouter(
  initialLocation: '/attendance',
  routes: [
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
  ],
);
