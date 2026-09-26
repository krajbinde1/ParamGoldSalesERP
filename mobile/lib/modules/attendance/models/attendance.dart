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
  bool get canPunchIn => punchInAllowed ?? punchIn == null;
  bool get canPunchOut =>
      punchOutAllowed ?? (punchIn != null && punchOut == null);

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
