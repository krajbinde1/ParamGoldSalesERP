<?php

use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeAttendanceEmployee(): Employee
{
    return Employee::create([
        'full_name' => 'Attendance Tester',
        'mobile' => '9876543210',
        'department' => 'Operations',
        'designation' => 'Executive',
        'joining_date' => '2026-01-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 0,
        'travel_allowance' => 0,
        'aadhaar_number' => '234567890123',
        'pan_number' => 'ABCDE1234F',
        'bank_name' => 'Test Bank',
        'account_number' => '123456789012',
        'ifsc_code' => 'TEST0123456',
        'status' => true,
    ]);
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

    expect($attendance->working_hours)->toBe('08:30');

    $attendance->update(['punch_out_time' => '18:00']);
    expect($attendance->fresh()->working_hours)->toBe('09:00');
});

it('filters attendance records and supports approve and reject states', function () {
    $employee = makeAttendanceEmployee();
    $present = Attendance::create(['employee_id' => $employee->id, 'attendance_date' => '2026-07-10', 'attendance_status' => 'Present', 'approval_status' => 'Pending']);
    $leave = Attendance::create(['employee_id' => $employee->id, 'attendance_date' => '2026-07-11', 'attendance_status' => 'Leave', 'approval_status' => 'Pending']);

    expect(Attendance::query()->where('employee_id', $employee->id)->whereDate('attendance_date', '2026-07-10')->where('attendance_status', 'Present')->count())->toBe(1);

    $present->update(['approval_status' => 'Approved', 'approved_by' => $employee->id]);
    $leave->update(['approval_status' => 'Rejected', 'approved_by' => $employee->id]);

    expect($present->fresh()->approval_status)->toBe('Approved')
        ->and($leave->fresh()->approval_status)->toBe('Rejected');
});

it('prevents duplicate attendance for one employee and date', function () {
    $employee = makeAttendanceEmployee();
    Attendance::create(['employee_id' => $employee->id, 'attendance_date' => '2026-07-10', 'attendance_status' => 'Present', 'approval_status' => 'Pending']);

    expect(fn () => Attendance::create(['employee_id' => $employee->id, 'attendance_date' => '2026-07-10', 'attendance_status' => 'Present', 'approval_status' => 'Pending']))
        ->toThrow(QueryException::class);
});
