import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/modules/attendance/models/attendance.dart';
import 'package:mobile/modules/attendance/models/attendance_format.dart';

void main() {
  test('working clock for a 10:26 punch in is about two minutes at 10:28', () {
    final punchIn = DateTime(2026, 9, 28, 10, 26);
    final now = DateTime(2026, 9, 28, 10, 28);
    expect(AttendanceFormat.workingClock(punchIn, now), '00:02:00');
    expect(
      AttendanceFormat.workingClock(
        DateTime(2026, 9, 25, 10, 26),
        DateTime(2026, 9, 28, 10, 28, 7),
      ),
      isNot('00:02:00'),
    );
  });

  test('no attendance payload leaves punch in available', () {
    final attendance = Attendance.parseTodayPayload({
      'attendance': null,
      'punch_in_allowed': true,
      'punch_out_allowed': false,
      'punch_out_correction_required': false,
      'punch_out_correction_pending': false,
      'is_current_session': false,
    });

    expect(attendance, isNull);
  });

  test('a two-hour session runs the live timer from that punch in', () {
    final attendance = Attendance.parseTodayPayload({
      'attendance': {
        'id': 9,
        'attendance_date': '2026-09-28',
        'punch_in_at': '2026-09-28T10:26:00+05:30',
        'punch_out_time': null,
        'attendance_status': 'Punched In',
      },
      'punch_in_allowed': false,
      'punch_out_allowed': true,
      'punch_out_correction_required': false,
      'is_current_session': true,
      'previous_open_attendance': null,
    });

    expect(attendance, isNotNull);
    expect(attendance!.runsLiveWorkingTimer, isTrue);
    expect(attendance.punchIn, DateTime(2026, 9, 28, 10, 26));
    expect(
      AttendanceFormat.workingClock(
        attendance.punchIn!,
        DateTime(2026, 9, 28, 10, 28),
      ),
      '00:02:00',
    );
    expect(attendance.canPunchOut, isTrue);
    expect(attendance.punchOutCorrectionRequired, isFalse);
  });

  test('expired attendance does not become the live working timer', () {
    final attendance = Attendance.parseTodayPayload({
      'attendance': null,
      'punch_in_allowed': false,
      'punch_out_allowed': false,
      'punch_out_correction_required': true,
      'punch_out_correction_pending': false,
      'previous_punch_out_pending': true,
      'is_current_session': false,
      'previous_open_attendance': {
        'id': 4,
        'attendance_date': '2026-09-25',
        'punch_in_at': '2026-09-25T10:26:00+05:30',
        'attendance_status': 'Punched In',
      },
      'late_punch_out_reasons': [
        {'value': 'forgot_to_punch_out', 'label': 'Forgot to Punch Out'},
      ],
    });

    expect(attendance, isNotNull);
    expect(attendance!.runsLiveWorkingTimer, isFalse);
    expect(attendance.punchIn, isNull);
    expect(attendance.openPunchIn, DateTime(2026, 9, 25, 10, 26));
    expect(attendance.punchOutCorrectionRequired, isTrue);
    expect(attendance.canPunchIn, isFalse);
    expect(attendance.canPunchOut, isFalse);
    expect(attendance.openAttendanceId, 4);
  });

  test('pending correction stays blocked and is not a silent completed day', () {
    final attendance = Attendance.parseTodayPayload({
      'attendance': null,
      'punch_in_allowed': false,
      'punch_out_allowed': false,
      'punch_out_correction_required': false,
      'punch_out_correction_pending': true,
      'previous_punch_out_pending': true,
      'is_current_session': false,
      'pending_correction': {
        'status': 'pending',
        'reason': 'forgot_to_punch_out',
        'requested_punch_out_time_label': '28 Sep 2026 06:00 PM',
      },
      'previous_open_attendance': {
        'id': 4,
        'attendance_date': '2026-09-25',
        'punch_in_at': '2026-09-25T10:26:00+05:30',
        'attendance_status': 'Punched In',
      },
    });

    expect(attendance!.punchOutCorrectionPending, isTrue);
    expect(attendance.punchOutCorrectionRequired, isFalse);
    expect(attendance.canPunchIn, isFalse);
    expect(attendance.canPunchOut, isFalse);
    expect(attendance.runsLiveWorkingTimer, isFalse);
    expect(attendance.pendingCorrection?['status'], 'pending');
  });

  test('completed attendance uses its own punch in and punch out', () {
    final punchIn = DateTime(2026, 9, 28, 10, 0);
    final punchOut = DateTime(2026, 9, 28, 12, 0);
    final attendance = Attendance(
      date: DateTime(2026, 9, 28),
      punchIn: punchIn,
      punchOut: punchOut,
      punchInAllowed: false,
      punchOutAllowed: false,
      isCurrentSession: false,
    );

    expect(attendance.runsLiveWorkingTimer, isFalse);
    expect(AttendanceFormat.workingClock(punchIn, punchOut), '02:00:00');
  });
}
