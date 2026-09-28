import 'attendance_format.dart';

class Attendance {
  const Attendance({
    this.id,
    this.employeeId,
    required this.date,
    this.punchIn,
    this.punchOut,
    this.inLatitude,
    this.inLongitude,
    this.outLatitude,
    this.outLongitude,
    this.inAddress,
    this.outAddress,
    this.inPhoto,
    this.outPhoto,
    this.workingHours,
    this.status = 'Absent',
    this.isPendingSync = false,
    this.previousPunchOutPending = false,
    this.punchInAllowed,
    this.punchOutAllowed,
    this.latePunchOutReasonRequired = false,
    this.punchOutCorrectionRequired = false,
    this.punchOutCorrectionPending = false,
    this.isLatePunchOut = false,
    this.latePunchOutReason,
    this.latePunchOutReasonLabel,
    this.punchOutCorrectionStatus,
    this.pendingCorrection,
    this.latePunchOutReasons = const [],
    this.isCurrentSession,
    this.openAttendanceId,
    this.openAttendanceDate,
    this.openPunchIn,
  });
  final int? id, employeeId;
  final DateTime date;
  final DateTime? punchIn, punchOut;
  final double? inLatitude, inLongitude, outLatitude, outLongitude;
  final String? inAddress, outAddress, inPhoto, outPhoto, workingHours;
  final String status;
  final bool isPendingSync;
  final bool previousPunchOutPending;
  final bool? punchInAllowed;
  final bool? punchOutAllowed;
  final bool latePunchOutReasonRequired;
  final bool punchOutCorrectionRequired;
  final bool punchOutCorrectionPending;
  final bool isLatePunchOut;
  final String? latePunchOutReason;
  final String? latePunchOutReasonLabel;
  final String? punchOutCorrectionStatus;
  final Map<String, dynamic>? pendingCorrection;
  final List<Map<String, String>> latePunchOutReasons;
  final bool? isCurrentSession;
  final int? openAttendanceId;
  final DateTime? openAttendanceDate;
  final DateTime? openPunchIn;
  bool get canPunchIn => punchInAllowed ?? punchIn == null;
  bool get canPunchOut =>
      punchOutAllowed ?? (punchIn != null && punchOut == null);

  /// Live working clock only for the real current session (< 24 hours).
  bool get runsLiveWorkingTimer {
    if (isCurrentSession == false) return false;
    if (punchIn == null || punchOut != null || !canPunchOut) return false;
    if ((punchOutCorrectionRequired || punchOutCorrectionPending) &&
        openPunchIn != null &&
        punchIn == null) {
      return false;
    }
    return true;
  }

  factory Attendance.fromJson(Map<String, dynamic> j) {
    final date = AttendanceFormat.parseDate(j['date'] ?? j['attendance_date']);
    double? number(dynamic v) => v == null ? null : double.tryParse('$v');
    final minutes = int.tryParse('${j['total_working_minutes'] ?? ''}');
    bool flag(dynamic v) => v == true || v == 1 || v == '1' || v == 'true';
    List<Map<String, String>> reasons() {
      final raw = j['late_punch_out_reasons'];
      if (raw is! List) return const [];
      return raw
          .whereType<Map>()
          .map(
            (e) => {
              'value': '${e['value'] ?? ''}',
              'label': '${e['label'] ?? e['value'] ?? ''}',
            },
          )
          .where((e) => e['value']!.isNotEmpty)
          .toList();
    }

    return Attendance(
      id: int.tryParse('${j['id'] ?? ''}'),
      employeeId: int.tryParse('${j['employee_id'] ?? ''}'),
      date: date,
      punchIn: AttendanceFormat.parseIstDateTime(
        date,
        j['punch_in_at'] ??
            j['punch_in'] ??
            j['punch_in_time_ist'] ??
            j['punch_in_time'],
      ),
      punchOut: AttendanceFormat.parseIstDateTime(
        date,
        j['punch_out_at'] ??
            j['punch_out'] ??
            j['punch_out_time_ist'] ??
            j['punch_out_time'],
      ),
      inLatitude: number(j['in_latitude'] ?? j['punch_in_latitude']),
      inLongitude: number(j['in_longitude'] ?? j['punch_in_longitude']),
      outLatitude: number(j['out_latitude'] ?? j['punch_out_latitude']),
      outLongitude: number(j['out_longitude'] ?? j['punch_out_longitude']),
      inAddress:
          j['in_address']?.toString() ?? j['punch_in_location']?.toString(),
      outAddress:
          j['out_address']?.toString() ?? j['punch_out_location']?.toString(),
      inPhoto: j['in_photo']?.toString() ?? j['punch_in_photo']?.toString(),
      outPhoto: j['out_photo']?.toString() ?? j['punch_out_photo']?.toString(),
      workingHours:
          j['working_hours']?.toString() ??
          (minutes == null ? null : '${minutes ~/ 60}h ${minutes % 60}m'),
      status: '${j['status'] ?? j['attendance_status'] ?? 'Absent'}',
      isPendingSync: j['is_pending_sync'] == true,
      previousPunchOutPending: flag(j['previous_punch_out_pending']),
      punchInAllowed: j.containsKey('punch_in_allowed')
          ? flag(j['punch_in_allowed'])
          : null,
      punchOutAllowed: j.containsKey('punch_out_allowed')
          ? flag(j['punch_out_allowed'])
          : null,
      latePunchOutReasonRequired: flag(j['late_punch_out_reason_required']),
      punchOutCorrectionRequired: flag(j['punch_out_correction_required']),
      punchOutCorrectionPending: flag(j['punch_out_correction_pending']),
      isLatePunchOut: flag(j['is_late_punch_out']),
      latePunchOutReason: j['late_punch_out_reason']?.toString(),
      latePunchOutReasonLabel: j['late_punch_out_reason_label']?.toString(),
      punchOutCorrectionStatus: j['punch_out_correction_status']?.toString(),
      pendingCorrection: j['pending_correction'] is Map
          ? Map<String, dynamic>.from(j['pending_correction'] as Map)
          : null,
      latePunchOutReasons: reasons(),
      isCurrentSession: j.containsKey('is_current_session')
          ? flag(j['is_current_session'])
          : null,
      openAttendanceId: int.tryParse('${j['open_attendance_id'] ?? ''}'),
      openAttendanceDate: j['open_attendance_date'] == null
          ? null
          : AttendanceFormat.parseDate(j['open_attendance_date']),
      openPunchIn: j['open_punch_in'] == null
          ? null
          : AttendanceFormat.parseIstDateTime(
              AttendanceFormat.parseDate(
                j['open_attendance_date'] ?? j['date'] ?? j['attendance_date'],
              ),
              j['open_punch_in'],
            ),
    );
  }

  static Attendance? parseTodayPayload(Map<String, dynamic> payload) {
    bool flag(dynamic v) => v == true || v == 1 || v == '1' || v == 'true';
    final flags = Map<String, dynamic>.from(payload)
      ..remove('attendance')
      ..remove('previous_open_attendance')
      ..remove('correction');

    Attendance? current;
    final raw = payload['attendance'];
    if (raw is Map) {
      current = Attendance.fromJson({
        ...Map<String, dynamic>.from(raw),
        ...flags,
      });
    }

    Attendance? previous;
    final previousRaw = payload['previous_open_attendance'];
    if (previousRaw is Map) {
      previous = Attendance.fromJson(Map<String, dynamic>.from(previousRaw));
    }

    final correction = flag(flags['punch_out_correction_required']);
    final pending = flag(flags['punch_out_correction_pending']);
    final previousPending = flag(flags['previous_punch_out_pending']);
    final punchInAllowed = flags.containsKey('punch_in_allowed')
        ? flag(flags['punch_in_allowed'])
        : true;

    Attendance attachPrevious(Attendance value) {
      if (previous == null) return value;
      return Attendance(
        id: value.id,
        employeeId: value.employeeId,
        date: value.date,
        punchIn: value.punchIn,
        punchOut: value.punchOut,
        inLatitude: value.inLatitude,
        inLongitude: value.inLongitude,
        outLatitude: value.outLatitude,
        outLongitude: value.outLongitude,
        inAddress: value.inAddress,
        outAddress: value.outAddress,
        inPhoto: value.inPhoto,
        outPhoto: value.outPhoto,
        workingHours: value.workingHours,
        status: value.status,
        isPendingSync: value.isPendingSync,
        previousPunchOutPending: value.previousPunchOutPending,
        punchInAllowed: value.punchInAllowed,
        punchOutAllowed: value.punchOutAllowed,
        latePunchOutReasonRequired: value.latePunchOutReasonRequired,
        punchOutCorrectionRequired: value.punchOutCorrectionRequired,
        punchOutCorrectionPending: value.punchOutCorrectionPending,
        isLatePunchOut: value.isLatePunchOut,
        latePunchOutReason: value.latePunchOutReason,
        latePunchOutReasonLabel: value.latePunchOutReasonLabel,
        punchOutCorrectionStatus: value.punchOutCorrectionStatus,
        pendingCorrection: value.pendingCorrection,
        latePunchOutReasons: value.latePunchOutReasons,
        isCurrentSession: value.isCurrentSession,
        openAttendanceId: previous.id,
        openAttendanceDate: previous.date,
        openPunchIn: previous.punchIn,
      );
    }

    if (current != null) {
      return attachPrevious(current);
    }

    if (previous != null && (correction || pending || previousPending)) {
      List<Map<String, String>> reasons() {
        final rawReasons = flags['late_punch_out_reasons'];
        if (rawReasons is! List) return const [];
        return rawReasons
            .whereType<Map>()
            .map(
              (e) => {
                'value': '${e['value'] ?? ''}',
                'label': '${e['label'] ?? e['value'] ?? ''}',
              },
            )
            .where((e) => e['value']!.isNotEmpty)
            .toList();
      }

      return Attendance(
        id: previous.id,
        employeeId: previous.employeeId,
        date: previous.date,
        status: pending ? 'Correction Pending' : 'Previous Punch Out Pending',
        previousPunchOutPending: true,
        punchInAllowed: false,
        punchOutAllowed: flag(flags['punch_out_allowed']),
        latePunchOutReasonRequired: flag(flags['late_punch_out_reason_required']),
        punchOutCorrectionRequired: correction,
        punchOutCorrectionPending: pending,
        isCurrentSession: false,
        pendingCorrection: flags['pending_correction'] is Map
            ? Map<String, dynamic>.from(flags['pending_correction'] as Map)
            : null,
        latePunchOutReasons: reasons(),
        openAttendanceId: previous.id,
        openAttendanceDate: previous.date,
        openPunchIn: previous.punchIn,
        isLatePunchOut: previous.isLatePunchOut,
      );
    }

    if (punchInAllowed) return null;

    return Attendance(
      date: AttendanceFormat.istNow(),
      punchInAllowed: false,
      punchOutAllowed: false,
      isCurrentSession: false,
      status: 'Absent',
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'employee_id': employeeId,
    'date': date.toIso8601String().substring(0, 10),
    'punch_in': punchIn == null
        ? null
        : '${date.toIso8601String().substring(0, 10)} ${punchIn!.hour.toString().padLeft(2, '0')}:${punchIn!.minute.toString().padLeft(2, '0')}:${punchIn!.second.toString().padLeft(2, '0')}',
    'punch_out': punchOut == null
        ? null
        : '${date.toIso8601String().substring(0, 10)} ${punchOut!.hour.toString().padLeft(2, '0')}:${punchOut!.minute.toString().padLeft(2, '0')}:${punchOut!.second.toString().padLeft(2, '0')}',
    'in_latitude': inLatitude,
    'in_longitude': inLongitude,
    'out_latitude': outLatitude,
    'out_longitude': outLongitude,
    'in_address': inAddress,
    'out_address': outAddress,
    'in_photo': inPhoto,
    'out_photo': outPhoto,
    'working_hours': workingHours,
    'status': status,
    'is_pending_sync': isPendingSync,
    'previous_punch_out_pending': previousPunchOutPending,
    'punch_in_allowed': punchInAllowed,
    'punch_out_allowed': punchOutAllowed,
    'late_punch_out_reason_required': latePunchOutReasonRequired,
    'punch_out_correction_required': punchOutCorrectionRequired,
    'punch_out_correction_pending': punchOutCorrectionPending,
    'is_late_punch_out': isLatePunchOut,
    'late_punch_out_reason': latePunchOutReason,
    'late_punch_out_reason_label': latePunchOutReasonLabel,
    'punch_out_correction_status': punchOutCorrectionStatus,
    'pending_correction': pendingCorrection,
    'late_punch_out_reasons': latePunchOutReasons,
    'is_current_session': isCurrentSession,
    'open_attendance_id': openAttendanceId,
    'open_attendance_date': openAttendanceDate == null
        ? null
        : '${openAttendanceDate!.year.toString().padLeft(4, '0')}-${openAttendanceDate!.month.toString().padLeft(2, '0')}-${openAttendanceDate!.day.toString().padLeft(2, '0')}',
    'open_punch_in': openPunchIn == null
        ? null
        : '${openPunchIn!.year.toString().padLeft(4, '0')}-${openPunchIn!.month.toString().padLeft(2, '0')}-${openPunchIn!.day.toString().padLeft(2, '0')} '
            '${openPunchIn!.hour.toString().padLeft(2, '0')}:${openPunchIn!.minute.toString().padLeft(2, '0')}:${openPunchIn!.second.toString().padLeft(2, '0')}',
  };
}

class AttendanceMonthlySummary {
  const AttendanceMonthlySummary({
    required this.month,
    required this.year,
    required this.workingDays,
    required this.presentDays,
    required this.halfDays,
    required this.absentDays,
    required this.punchInDays,
    required this.punchOutDays,
  });

  final int month;
  final int year;
  final int workingDays;
  final int presentDays;
  final int halfDays;
  final int absentDays;
  final int punchInDays;
  final int punchOutDays;

  factory AttendanceMonthlySummary.fromJson(Map<String, dynamic> json) =>
      AttendanceMonthlySummary(
        month: int.tryParse('${json['month'] ?? ''}') ?? DateTime.now().month,
        year: int.tryParse('${json['year'] ?? ''}') ?? DateTime.now().year,
        workingDays: int.tryParse('${json['working_days'] ?? ''}') ?? 0,
        presentDays: int.tryParse('${json['present_days'] ?? ''}') ?? 0,
        halfDays: int.tryParse('${json['half_days'] ?? ''}') ?? 0,
        absentDays: int.tryParse('${json['absent_days'] ?? ''}') ?? 0,
        punchInDays: int.tryParse('${json['punch_in_days'] ?? ''}') ?? 0,
        punchOutDays: int.tryParse('${json['punch_out_days'] ?? ''}') ?? 0,
      );
}
