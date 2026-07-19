import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../api/attendance_api_service.dart';
import '../models/attendance.dart';
import '../repository/attendance_repository.dart';
import '../repository/capture_service.dart';
import '../route_tracking/route_tracking_log.dart';
import '../route_tracking/route_tracking_provider.dart';
import '../route_tracking/route_tracking_service.dart';

final repositoryProvider = FutureProvider<AttendanceRepository>(
  (ref) async => AttendanceRepository(
    await AttendanceApiService.create(),
    await SharedPreferences.getInstance(),
  ),
);
final todayAttendanceProvider =
    AsyncNotifierProvider<TodayAttendanceNotifier, Attendance?>(
      TodayAttendanceNotifier.new,
    );

class TodayAttendanceNotifier extends AsyncNotifier<Attendance?> {
  @override
  Future<Attendance?> build() async {
    return (await ref.watch(repositoryProvider.future)).today();
  }

  Future<Attendance> punch(String action) async {
    if (state.isLoading) {
      throw StateError('Attendance request already in progress.');
    }
    final current = state.value;
    if (action == 'punch-in' && current?.punchIn != null) {
      throw const AttendanceApiException('You have already punched in today.');
    }
    if (action == 'punch-out' && current?.punchIn == null) {
      throw const AttendanceApiException(
        'Punch in is required before punch out.',
      );
    }

    if (action == 'punch-out') {
      try {
        await RouteTrackingService.instance.captureAndSyncBeforePunchOut();
        refreshRouteTrackingStatusFromRef(ref);
      } catch (error, stackTrace) {
        routeTrackingLog(
          'Pre punch-out route sync failed (non-blocking): $error\n$stackTrace',
        );
      }
    }

    state = const AsyncLoading();
    state = await AsyncValue.guard(() async {
      return (await ref.read(
        repositoryProvider.future,
      )).punch(action, await CaptureService().capture());
    });
    if (state.hasError) throw state.error!;

    final attendance = state.requireValue!;

    if (action == 'punch-in') {
      try {
        final attendanceId = attendance.id;
        if (attendanceId != null) {
          await RouteTrackingService.instance.start(attendanceId);
        }
      } catch (error, stackTrace) {
        routeTrackingLog(
          'Route tracking start failed after punch-in (non-blocking): '
          '$error\n$stackTrace',
        );
      }
    } else if (action == 'punch-out') {
      try {
        await RouteTrackingService.instance.stop();
      } catch (error, stackTrace) {
        routeTrackingLog(
          'Route tracking stop failed after punch-out (non-blocking): '
          '$error\n$stackTrace',
        );
      }
    }

    refreshRouteTrackingStatusFromRef(ref);
    return attendance;
  }

  Future<void> refresh() =>
      ref.refresh(repositoryProvider.future).then((r) async {
        state = await AsyncValue.guard(r.today);
      });

  // TEST ONLY - REMOVE BEFORE PRODUCTION
  Future<String> resetTodayTest() async {
    final repo = await ref.read(repositoryProvider.future);

    state = const AsyncLoading();
    late String message;
    state = await AsyncValue.guard(() async {
      message = await repo.resetTodayTest();
      return repo.fetchTodayFresh();
    });
    if (state.hasError) throw state.error!;
    return message;
  }
}

final attendanceMonthProvider =
    FutureProvider.family<List<Attendance>, DateTime>(
      (ref, month) async =>
          (await ref.watch(repositoryProvider.future)).history(month),
    );

final attendanceMonthlySummaryProvider =
    FutureProvider.family<AttendanceMonthlySummary, DateTime>(
      (ref, month) async =>
          (await ref.watch(repositoryProvider.future)).monthlySummary(month),
    );
