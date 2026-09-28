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

  Future<Attendance?> punch(
    String action, {
    String? latePunchOutReason,
    String? latePunchOutReasonNote,
  }) async {
    if (state.isLoading) {
      throw StateError('Attendance request already in progress.');
    }
    final current = state.value;
    if (action == 'punch-in' && current?.previousPunchOutPending == true) {
      throw const AttendanceApiException('Previous Punch Out Pending');
    }
    if (action == 'punch-in' && current?.canPunchIn == false) {
      throw const AttendanceApiException('You have already punched in today.');
    }
    if (action == 'punch-out' &&
        current?.punchIn == null &&
        current?.canPunchOut != true) {
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

    final previousState = state;
    state = const AsyncLoading();
    state = await AsyncValue.guard(() async {
      final repo = await ref.read(repositoryProvider.future);
      final punched = await repo.punch(
        action,
        await CaptureService().capture(),
        latePunchOutReason: latePunchOutReason,
        latePunchOutReasonNote: latePunchOutReasonNote,
      );
      try {
        return await repo.fetchTodayFromServer();
      } catch (error) {
        routeTrackingLog('Today refresh after $action failed: $error');
        return punched;
      }
    });
    if (state.hasError) {
      final error = state.error!;
      state = previousState;
      throw error;
    }

    final attendance = state.asData?.value;

    if (action == 'punch-in') {
      try {
        final attendanceId = attendance?.id;
        if (attendanceId != null && attendance?.runsLiveWorkingTimer == true) {
          await RouteTrackingService.instance.start(
            attendanceId,
            employeeId: attendance?.employeeId,
          );
        }
      } catch (error, stackTrace) {
        routeTrackingLog(
          'Route tracking start failed after punch-in (non-blocking): '
          '$error\n$stackTrace',
        );
      }
    } else if (action == 'punch-out') {
      // Only stop FGS / clear local tracking after punch-out API succeeded.
      try {
        await RouteTrackingService.instance.stop();
        routeTrackingLog('Punch Out success — tracking cleared');
      } catch (error, stackTrace) {
        routeTrackingLog(
          'Route tracking stop failed after punch-out (non-blocking): '
          '$error\n$stackTrace',
        );
      }
    }

    await refreshRouteTrackingStatusFromRef(ref);
    return attendance;
  }

  Future<Attendance?> submitPunchOutCorrection({
    required DateTime actualPunchOut,
    required String reason,
    String? reasonNote,
  }) async {
    if (state.isLoading) {
      throw StateError('Attendance request already in progress.');
    }
    final previousState = state;
    state = const AsyncLoading();
    state = await AsyncValue.guard(() async {
      final repo = await ref.read(repositoryProvider.future);
      final submitted = await repo.submitPunchOutCorrection(
        actualPunchOut: actualPunchOut,
        reason: reason,
        reasonNote: reasonNote,
      );
      try {
        return await repo.fetchTodayFromServer();
      } catch (error) {
        routeTrackingLog('Today refresh after correction failed: $error');
        return submitted;
      }
    });
    if (state.hasError) {
      final error = state.error!;
      state = previousState;
      throw error;
    }
    final attendance = state.asData?.value;
    try {
      await RouteTrackingService.instance.closeStaleSession(
        attendanceId: attendance?.openAttendanceId ?? attendance?.id,
      );
    } catch (error, stackTrace) {
      routeTrackingLog(
        'Stale route close after correction failed: $error\n$stackTrace',
      );
    }
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
