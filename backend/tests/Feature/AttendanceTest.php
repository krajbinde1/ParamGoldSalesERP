<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Services\Attendance\AttendanceStatusCalculator;
use App\Support\AttendanceCalendar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function makeAttendanceEmployee(array $overrides = []): Employee
{
    static $n = 0;
    $n++;

    return Employee::create(array_merge([
        'full_name' => 'Attendance Tester '.$n,
        'mobile' => (string) (9800000000 + $n),
        'department' => 'Operations',
        'designation' => 'Executive',
        'joining_date' => '2026-01-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 0,
        'travel_allowance' => 0,
        'aadhaar_number' => str_pad((string) (200000000000 + $n), 12, '0', STR_PAD_LEFT),
        'pan_number' => 'ABCDE'.str_pad((string) $n, 4, '0', STR_PAD_LEFT).'F',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad((string) (100000000000 + $n), 12, '0', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
    ], $overrides));
}

it('creates and edits attendance while calculating working hours', function () {
    $employee = makeAttendanceEmployee();
    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-07-10',
        'punch_in_time' => '09:00',
        'punch_out_time' => '17:30',
        'attendance_status' => 'Present',
        'approval_status' => 'Pending',
    ]);

    expect($attendance->working_hours)->toBe('08:30')
        ->and($attendance->attendance_status)->toBe(AttendanceStatusCalculator::STATUS_PRESENT);

    $attendance->update(['punch_out_time' => '18:00']);
    expect($attendance->fresh()->working_hours)->toBe('09:00');
});

it('filters attendance records and supports approve and reject states', function () {
    $employee = makeAttendanceEmployee();
    $present = Attendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-07-10',
        'punch_in_time' => '09:00',
        'punch_out_time' => '18:00',
        'attendance_status' => 'Present',
        'approval_status' => 'Pending',
    ]);
    $leave = Attendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-07-11',
        'attendance_status' => 'Leave',
        'approval_status' => 'Pending',
    ]);

    expect(Attendance::query()->where('employee_id', $employee->id)->whereDate('attendance_date', '2026-07-10')->where('attendance_status', 'Present')->count())->toBe(1)
        ->and($leave->fresh()->attendance_status)->toBe('Leave');

    $present->update(['approval_status' => 'Approved', 'approved_by' => $employee->id]);
    $leave->update(['approval_status' => 'Rejected', 'approved_by' => $employee->id]);

    expect($present->fresh()->approval_status)->toBe('Approved')
        ->and($leave->fresh()->approval_status)->toBe('Rejected');
});

it('prevents duplicate attendance for one employee and date', function () {
    $employee = makeAttendanceEmployee();
    Attendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-07-10',
        'punch_in_time' => '09:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    expect(fn () => Attendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-07-10',
        'punch_in_time' => '10:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]))->toThrow(QueryException::class);
});

it('marks punch in only as Punched In not Present', function () {
    $employee = makeAttendanceEmployee();

    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-08-11',
        'punch_in_time' => '09:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    expect($attendance->attendance_status)->toBe(AttendanceStatusCalculator::STATUS_PUNCHED_IN)
        ->and($attendance->working_hours)->toBeNull();
});

it('calculates Present for exactly 8 hours and longer', function () {
    $calculator = app(AttendanceStatusCalculator::class);
    $employee = makeAttendanceEmployee();

    $eight = Attendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-08-10',
        'punch_in_time' => '09:00:00',
        'punch_out_time' => '17:00:00',
        'approval_status' => 'Pending',
    ]);

    $nine = Attendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-08-11',
        'punch_in_time' => '09:00:00',
        'punch_out_time' => '18:00:00',
        'approval_status' => 'Pending',
    ]);

    expect($eight->attendance_status)->toBe(AttendanceStatusCalculator::STATUS_PRESENT)
        ->and($nine->attendance_status)->toBe(AttendanceStatusCalculator::STATUS_PRESENT)
        ->and($calculator->calculate('09:00:00', '17:00:00', '2026-08-10'))->toBe(AttendanceStatusCalculator::STATUS_PRESENT)
        ->and($calculator->calculate('09:00:00', '18:00:00', '2026-08-10'))->toBe(AttendanceStatusCalculator::STATUS_PRESENT);
});

it('calculates Half Day for 4 hours up to under 8 hours', function () {
    $calculator = app(AttendanceStatusCalculator::class);
    $employee = makeAttendanceEmployee();

    $four = Attendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-08-10',
        'punch_in_time' => '09:00:00',
        'punch_out_time' => '13:00:00',
        'approval_status' => 'Pending',
    ]);

    $six = Attendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-08-11',
        'punch_in_time' => '09:00:00',
        'punch_out_time' => '15:00:00',
        'approval_status' => 'Pending',
    ]);

    expect($four->attendance_status)->toBe(AttendanceStatusCalculator::STATUS_HALF_DAY)
        ->and($six->attendance_status)->toBe(AttendanceStatusCalculator::STATUS_HALF_DAY)
        ->and($calculator->calculate('09:00:00', '12:59:00', '2026-08-10'))->toBe(AttendanceStatusCalculator::STATUS_ABSENT);
});

it('calculates Absent under 4 hours', function () {
    $employee = makeAttendanceEmployee();

    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-08-10',
        'punch_in_time' => '09:00:00',
        'punch_out_time' => '12:59:00',
        'approval_status' => 'Pending',
    ]);

    expect($attendance->attendance_status)->toBe(AttendanceStatusCalculator::STATUS_ABSENT)
        ->and($attendance->total_working_minutes)->toBe(239);
});

it('does not count open punch today as Absent in monthly summary', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', AttendanceCalendar::TIMEZONE));

    $employee = makeAttendanceEmployee();

    // Completed prior working day with no attendance → Absent
    // Today punched in only → Punched In (excluded from absent)
    Attendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-08-11',
        'punch_in_time' => '09:00:00',
        'approval_status' => 'Pending',
    ]);

    $summary = Attendance::adminEmployeeMonthlySummary($employee->id, 8, 2026);

    expect($summary['present_days'])->toBe(0)
        ->and($summary['half_days'])->toBe(0)
        ->and($summary['absent_days'])->toBeGreaterThan(0);

    // Today's open punch must not inflate absent beyond completed working days without attendance.
    $periodStart = Carbon::create(2026, 8, 1, 0, 0, 0, AttendanceCalendar::TIMEZONE)->startOfDay();
    $periodEnd = AttendanceCalendar::periodEndForMonth(8, 2026);
    $workingDays = AttendanceCalendar::workingDaysInPeriod($periodStart, $periodEnd);

    expect($summary['working_days'])->toBe($workingDays)
        ->and($summary['absent_days'])->toBe($workingDays - 1); // today punched-in excluded

    Carbon::setTestNow();
});

it('preserves Leave status and does not overwrite it from punches', function () {
    $employee = makeAttendanceEmployee();

    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-08-10',
        'punch_in_time' => '09:00:00',
        'punch_out_time' => '18:00:00',
        'attendance_status' => 'Leave',
        'approval_status' => 'Pending',
    ]);

    expect($attendance->fresh()->attendance_status)->toBe('Leave');
});
