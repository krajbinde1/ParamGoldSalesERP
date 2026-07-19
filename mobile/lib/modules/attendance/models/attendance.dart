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
  });
  final int? id, employeeId;
  final DateTime date;
  final DateTime? punchIn, punchOut;
  final double? inLatitude, inLongitude, outLatitude, outLongitude;
  final String? inAddress, outAddress, inPhoto, outPhoto, workingHours;
  final String status;
  final bool isPendingSync;
  bool get canPunchIn => punchIn == null;
  bool get canPunchOut => punchIn != null && punchOut == null;

  factory Attendance.fromJson(Map<String, dynamic> j) {
    final date = AttendanceFormat.parseDate(j['date'] ?? j['attendance_date']);
    double? number(dynamic v) => v == null ? null : double.tryParse('$v');
    final minutes = int.tryParse('${j['total_working_minutes'] ?? ''}');

    return Attendance(
      id: int.tryParse('${j['id'] ?? ''}'),
      employeeId: int.tryParse('${j['employee_id'] ?? ''}'),
      date: date,
      punchIn: AttendanceFormat.parseIstDateTime(
        date,
        j['punch_in_time'] ??
            j['punch_in_time_ist'] ??
            j['punch_in_at'] ??
            j['punch_in'],
      ),
      punchOut: AttendanceFormat.parseIstDateTime(
        date,
        j['punch_out_time'] ??
            j['punch_out_time_ist'] ??
            j['punch_out_at'] ??
            j['punch_out'],
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
  };
}

class AttendanceMonthlySummary {
  const AttendanceMonthlySummary({
    required this.month,
    required this.year,
    required this.workingDays,
    required this.presentDays,
    required this.absentDays,
    required this.punchInDays,
    required this.punchOutDays,
  });

  final int month;
  final int year;
  final int workingDays;
  final int presentDays;
  final int absentDays;
  final int punchInDays;
  final int punchOutDays;

  factory AttendanceMonthlySummary.fromJson(Map<String, dynamic> json) =>
      AttendanceMonthlySummary(
        month: int.tryParse('${json['month'] ?? ''}') ?? DateTime.now().month,
        year: int.tryParse('${json['year'] ?? ''}') ?? DateTime.now().year,
        workingDays: int.tryParse('${json['working_days'] ?? ''}') ?? 0,
        presentDays: int.tryParse('${json['present_days'] ?? ''}') ?? 0,
        absentDays: int.tryParse('${json['absent_days'] ?? ''}') ?? 0,
        punchInDays: int.tryParse('${json['punch_in_days'] ?? ''}') ?? 0,
        punchOutDays: int.tryParse('${json['punch_out_days'] ?? ''}') ?? 0,
      );
}
