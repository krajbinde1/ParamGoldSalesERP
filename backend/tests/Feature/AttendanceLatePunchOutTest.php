<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendancePunchOutCorrection;
use App\Services\Attendance\AttendanceStatusCalculator;
use App\Support\AttendanceCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    Carbon::setTestNow(Carbon::parse('2026-09-26 10:00:00', AttendanceCalendar::TIMEZONE));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function latePunchEmployee(string $name, string $mobile, UserRole $role = UserRole::Employee): \App\Models\Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $name,
        'mobile' => $mobile,
        'email' => strtolower(str_replace(' ', '.', $name)).'.'.$mobile.'@example.com',
        'department' => 'Sales',
        'designation' => $role->label(),
        'joining_date' => '2026-01-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 300,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => '23456789'.substr($mobile, -4),
        'pan_number' => 'ABCDE123'.substr($mobile, -1).'F',
        'bank_name' => 'Test Bank',
        'account_number' => '12345678901'.substr($mobile, -1),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => $role->value,
    ])->employee->refresh();
}

function punchPayload(array $extra = []): array
{
    return array_merge([
        'latitude' => 18.5204,
        'longitude' => 73.8567,
        'location_address' => 'Pune',
        'photo' => UploadedFile::fake()->image('selfie.jpg'),
    ], $extra);
}

it('keeps same-day punch out without a late reason', function (): void {
    $employee = latePunchEmployee('Same Day Punch', '9600000101');

    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-26',
        'punch_in_time' => '09:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/attendance/punch-out', punchPayload())
        ->assertOk()
        ->assertJsonPath('message', 'Punch out recorded.');

    $attendance = Attendance::query()->where('employee_id', $employee->id)->first();

    expect($attendance->punch_out_time)->not->toBeNull()
        ->and($attendance->punch_out_at)->toBeNull()
        ->and($attendance->is_late_punch_out)->toBeFalse()
        ->and($attendance->attendance_status)->toBe(AttendanceStatusCalculator::STATUS_ABSENT);
});

it('blocks a new punch in while previous punch out is pending', function (): void {
    $employee = latePunchEmployee('Pending Punch', '9600000102');

    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-25',
        'punch_in_time' => '18:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.previous_punch_out_pending', true)
        ->assertJsonPath('data.punch_in_allowed', false)
        ->assertJsonPath('data.punch_out_allowed', true)
        ->assertJsonPath('data.late_punch_out_reason_required', true)
        ->assertJsonPath('data.banner', 'Previous Punch Out Pending');

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/attendance/punch-in', punchPayload())
        ->assertStatus(422)
        ->assertJsonPath('message', 'Previous Punch Out Pending');
});

it('records a late punch out under 24 hours with a mandatory reason', function (): void {
    $employee = latePunchEmployee('Late Punch', '9600000103');

    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-25',
        'punch_in_time' => '18:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/attendance/punch-out', punchPayload())
        ->assertStatus(422);

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/attendance/punch-out', punchPayload([
            'late_punch_out_reason' => AttendancePunchOutCorrection::REASON_FORGOT,
        ]))
        ->assertOk()
        ->assertJsonPath('message', 'Late punch out recorded.');

    $fresh = $attendance->fresh();

    expect($fresh->is_late_punch_out)->toBeTrue()
        ->and($fresh->late_punch_out_reason)->toBe(AttendancePunchOutCorrection::REASON_FORGOT)
        ->and($fresh->punch_out_at)->not->toBeNull()
        ->and($fresh->total_working_minutes)->toBe(16 * 60)
        ->and($fresh->attendance_status)->toBe(AttendanceStatusCalculator::STATUS_PRESENT);
});

it('requires a punch out correction after 24 hours and does not close with now', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-27 10:30:00', AttendanceCalendar::TIMEZONE));
    $employee = latePunchEmployee('Correction Needed', '9600000104');

    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-25',
        'punch_in_time' => '09:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.punch_out_correction_required', true)
        ->assertJsonPath('data.punch_out_allowed', false)
        ->assertJsonPath('data.punch_in_allowed', false);

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/attendance/punch-out', punchPayload([
            'late_punch_out_reason' => AttendancePunchOutCorrection::REASON_TRAVELLING,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Request Punch Out Correction');

    expect($attendance->fresh()->punch_out_time)->toBeNull()
        ->and($attendance->fresh()->total_working_minutes)->toBeNull();
});

it('approves a punch out correction and recalculates working hours from requested time', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-27 10:30:00', AttendanceCalendar::TIMEZONE));

    $manager = latePunchEmployee('Team Manager', '9600000105', UserRole::Manager);
    $employee = latePunchEmployee('Report Employee', '9600000106');
    $employee->update(['reporting_manager_id' => $manager->id]);

    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-25',
        'punch_in_time' => '09:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/attendance/punch-out-correction', [
            'actual_punch_out_date' => '2026-09-25',
            'actual_punch_out_time' => '17:00',
            'reason' => AttendancePunchOutCorrection::REASON_FORGOT,
        ])
        ->assertCreated();

    $correction = AttendancePunchOutCorrection::query()->first();
    expect($correction?->status)->toBe(AttendancePunchOutCorrection::STATUS_PENDING)
        ->and($attendance->fresh()->punch_out_time)->toBeNull()
        ->and($attendance->fresh()->punch_out_correction_status)->toBe('pending');

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/attendance/punch-in', punchPayload())
        ->assertStatus(422)
        ->assertJsonPath('message', 'Previous Punch Out Pending');

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/team-attendance/{$attendance->id}/punch-out-corrections/{$correction->id}/approve")
        ->assertOk();

    $fresh = $attendance->fresh();
    expect($fresh->punch_out_time)->toBe('17:00:00')
        ->and($fresh->is_late_punch_out)->toBeTrue()
        ->and($fresh->total_working_minutes)->toBe(480)
        ->and($fresh->attendance_status)->toBe(AttendanceStatusCalculator::STATUS_PRESENT)
        ->and($fresh->punch_out_correction_status)->toBe('approved')
        ->and($correction->fresh()->reviewed_by)->toBe($manager->user->id);
});

it('keeps attendance unresolved after rejection so the employee can resubmit', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-27 10:30:00', AttendanceCalendar::TIMEZONE));

    $manager = latePunchEmployee('Reject Manager', '9600000107', UserRole::Manager);
    $employee = latePunchEmployee('Resubmit Employee', '9600000108');
    $employee->update(['reporting_manager_id' => $manager->id]);

    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-25',
        'punch_in_time' => '09:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/attendance/punch-out-correction', [
            'actual_punch_out_date' => '2026-09-25',
            'actual_punch_out_time' => '13:00',
            'reason' => AttendancePunchOutCorrection::REASON_OTHER,
            'reason_note' => 'Client meeting ran late',
        ])
        ->assertCreated();

    $first = AttendancePunchOutCorrection::query()->latest('id')->first();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/team-attendance/{$attendance->id}/punch-out-corrections/{$first->id}/reject", [
            'remark' => 'Time looks incorrect',
        ])
        ->assertOk();

    expect($attendance->fresh()->punch_out_time)->toBeNull()
        ->and($attendance->fresh()->punch_out_correction_status)->toBe('rejected')
        ->and($first->fresh()->status)->toBe(AttendancePunchOutCorrection::STATUS_REJECTED);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/attendance/punch-out-correction', [
            'actual_punch_out_date' => '2026-09-25',
            'actual_punch_out_time' => '17:30',
            'reason' => AttendancePunchOutCorrection::REASON_TRAVELLING,
        ])
        ->assertCreated();

    $second = AttendancePunchOutCorrection::query()->latest('id')->first();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/team-attendance/{$attendance->id}/punch-out-corrections/{$second->id}/approve")
        ->assertOk();

    expect($attendance->fresh()->total_working_minutes)->toBe(510)
        ->and($attendance->fresh()->attendance_status)->toBe(AttendanceStatusCalculator::STATUS_PRESENT);
});

it('marks half day from the approved punch out datetime', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-27 11:00:00', AttendanceCalendar::TIMEZONE));
    $employee = latePunchEmployee('Half Day Correction', '9600000109');

    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-25',
        'punch_in_time' => '09:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/attendance/punch-out-correction', [
            'actual_punch_out_date' => '2026-09-25',
            'actual_punch_out_time' => '14:00',
            'reason' => AttendancePunchOutCorrection::REASON_FORGOT,
        ])
        ->assertCreated();

    $correction = AttendancePunchOutCorrection::query()->first();
    app(\App\Actions\Attendance\ApprovePunchOutCorrection::class)
        ->execute($correction, $employee->user);

    expect($attendance->fresh()->total_working_minutes)->toBe(300)
        ->and($attendance->fresh()->attendance_status)->toBe(AttendanceStatusCalculator::STATUS_HALF_DAY);
});

it('allows punch in when there is no open attendance', function (): void {
    $employee = latePunchEmployee('No Attendance', '9600000201');

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.attendance', null)
        ->assertJsonPath('data.punch_in_allowed', true)
        ->assertJsonPath('data.punch_out_allowed', false)
        ->assertJsonPath('data.punch_out_correction_required', false);

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/attendance/punch-in', punchPayload())
        ->assertCreated();

    expect(Attendance::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

it('allows a normal punch out two hours after punch in', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-28 10:00:00', AttendanceCalendar::TIMEZONE));
    $employee = latePunchEmployee('Two Hour Punch', '9600000202');

    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-28',
        'punch_in_time' => '08:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.punch_in_allowed', false)
        ->assertJsonPath('data.punch_out_allowed', true)
        ->assertJsonPath('data.punch_out_correction_required', false)
        ->assertJsonPath('data.late_punch_out_reason_required', false)
        ->assertJsonPath('data.is_current_session', true)
        ->assertJsonPath('data.open_elapsed_minutes', 120);
});

it('allows a normal punch out when midnight passed but elapsed time is 22 hours', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-29 08:00:00', AttendanceCalendar::TIMEZONE));
    $employee = latePunchEmployee('Twenty Two Hours', '9600000203');

    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-28',
        'punch_in_time' => '10:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.punch_out_allowed', true)
        ->assertJsonPath('data.punch_out_correction_required', false)
        ->assertJsonPath('data.late_punch_out_reason_required', true)
        ->assertJsonPath('data.is_current_session', true)
        ->assertJsonPath('data.open_elapsed_minutes', 22 * 60)
        ->assertJsonPath('data.punch_in_allowed', false);
});

it('blocks normal punch out after 25 hours and requires a previous punch out correction', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-29 11:00:00', AttendanceCalendar::TIMEZONE));
    $employee = latePunchEmployee('Twenty Five Hours', '9600000204');

    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-28',
        'punch_in_time' => '10:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.attendance', null)
        ->assertJsonPath('data.punch_out_allowed', false)
        ->assertJsonPath('data.punch_in_allowed', false)
        ->assertJsonPath('data.punch_out_correction_required', true)
        ->assertJsonPath('data.is_current_session', false)
        ->assertJsonPath('data.previous_open_attendance.id', $attendance->id)
        ->assertJsonPath('data.previous_open_attendance.attendance_date', '2026-09-28');

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/attendance/punch-out', punchPayload())
        ->assertStatus(422)
        ->assertJsonPath('message', 'Request Punch Out Correction');

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/attendance/punch-out-correction', [
            'actual_punch_out_date' => '2026-09-28',
            'actual_punch_out_time' => '18:00',
        ])
        ->assertStatus(422);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/attendance/punch-out-correction', [
            'actual_punch_out_date' => '2026-09-28',
            'actual_punch_out_time' => '18:00',
            'reason' => AttendancePunchOutCorrection::REASON_FORGOT,
        ])
        ->assertCreated();

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.punch_out_correction_pending', true)
        ->assertJsonPath('data.punch_out_correction_required', false)
        ->assertJsonPath('data.punch_in_allowed', false)
        ->assertJsonPath('data.punch_out_allowed', false)
        ->assertJsonPath('data.pending_correction.reason', AttendancePunchOutCorrection::REASON_FORGOT);

    expect($attendance->fresh()->punch_out_time)->toBeNull();
});

it('allows today punch in after the expired attendance correction is approved', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-29 11:00:00', AttendanceCalendar::TIMEZONE));
    $manager = latePunchEmployee('Resolve Manager', '9600000205', UserRole::Manager);
    $employee = latePunchEmployee('Resolve Employee', '9600000206');
    $employee->update(['reporting_manager_id' => $manager->id]);

    $expired = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-28',
        'punch_in_time' => '10:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/attendance/punch-out-correction', [
            'actual_punch_out_date' => '2026-09-28',
            'actual_punch_out_time' => '19:00',
            'reason' => AttendancePunchOutCorrection::REASON_TRAVELLING,
        ])
        ->assertCreated();

    $correction = AttendancePunchOutCorrection::query()->first();

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/attendance/punch-in', punchPayload())
        ->assertStatus(422);

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/team-attendance/{$expired->id}/punch-out-corrections/{$correction->id}/approve")
        ->assertOk();

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.punch_in_allowed', true)
        ->assertJsonPath('data.punch_out_allowed', false)
        ->assertJsonPath('data.punch_out_correction_required', false)
        ->assertJsonPath('data.punch_out_correction_pending', false)
        ->assertJsonPath('data.attendance', null);

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/attendance/punch-in', punchPayload())
        ->assertCreated();

    $today = Attendance::query()
        ->where('employee_id', $employee->id)
        ->whereDate('attendance_date', '2026-09-29')
        ->first();

    expect($today)->not->toBeNull()
        ->and($today->id)->not->toBe($expired->id)
        ->and($today->punch_in_time)->not->toBeNull()
        ->and($today->punch_out_time)->toBeNull()
        ->and($expired->fresh()->punch_out_time)->toBe('19:00:00');
});

it('allows today punch in when the previous attendance is already completed', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-28 10:28:00', AttendanceCalendar::TIMEZONE));
    $employee = latePunchEmployee('Completed Yesterday', '9600000207');

    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-27',
        'punch_in_time' => '10:00:00',
        'punch_out_time' => '18:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PRESENT,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.punch_in_allowed', true)
        ->assertJsonPath('data.attendance', null);

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/attendance/punch-in', punchPayload())
        ->assertCreated();
});

it('keeps a pending correction blocking punch in without a dead-end resubmit', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-29 11:00:00', AttendanceCalendar::TIMEZONE));
    $employee = latePunchEmployee('Pending Lock', '9600000208');

    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-28',
        'punch_in_time' => '10:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/attendance/punch-out-correction', [
            'actual_punch_out_date' => '2026-09-28',
            'actual_punch_out_time' => '18:30',
            'reason' => AttendancePunchOutCorrection::REASON_FORGOT,
        ])
        ->assertCreated();

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/attendance/punch-in', punchPayload())
        ->assertStatus(422);

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/attendance/punch-out', punchPayload())
        ->assertStatus(422)
        ->assertJsonPath('message', 'A punch-out correction is already pending approval.');

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/attendance/punch-out-correction', [
            'actual_punch_out_date' => '2026-09-28',
            'actual_punch_out_time' => '18:45',
            'reason' => AttendancePunchOutCorrection::REASON_FORGOT,
        ])
        ->assertStatus(422);

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.punch_out_correction_pending', true)
        ->assertJsonPath('data.punch_in_allowed', false)
        ->assertJsonPath('data.pending_correction.status', 'pending');
});

it('reports about two minutes of working time for a punch in at 10:26', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-28 10:28:00', AttendanceCalendar::TIMEZONE));
    $employee = latePunchEmployee('Two Minute Timer', '9600000209');

    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-28',
        'punch_in_time' => '10:26:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.is_current_session', true)
        ->assertJsonPath('data.open_elapsed_minutes', 2)
        ->assertJsonPath('data.punch_out_correction_required', false)
        ->assertJsonPath('data.attendance.attendance_date', '2026-09-28');
});

it('saves punch out time and working minutes for a normal punch out inside 24 hours', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-28 12:00:00', AttendanceCalendar::TIMEZONE));
    $employee = latePunchEmployee('Normal Close', '9600000210');

    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-28',
        'punch_in_time' => '10:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/attendance/punch-out', punchPayload())
        ->assertOk()
        ->assertJsonPath('message', 'Punch out recorded.');

    $fresh = $attendance->fresh();
    expect($fresh->punch_out_time)->toBe('12:00:00')
        ->and($fresh->total_working_minutes)->toBe(120)
        ->and($fresh->punch_out_time)->not->toBeNull();

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.punch_in_allowed', false)
        ->assertJsonPath('data.punch_out_allowed', false)
        ->assertJsonPath('data.is_current_session', false);
});

it('treats exactly 24 elapsed hours as expired and 23 hours 59 minutes as a normal punch out', function (): void {
    $employee = latePunchEmployee('Boundary Hours', '9600000211');

    Carbon::setTestNow(Carbon::parse('2026-09-29 09:59:00', AttendanceCalendar::TIMEZONE));
    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-28',
        'punch_in_time' => '10:00:00',
        'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.punch_out_allowed', true)
        ->assertJsonPath('data.punch_out_correction_required', false);

    Carbon::setTestNow(Carbon::parse('2026-09-29 10:00:00', AttendanceCalendar::TIMEZONE));

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.punch_out_allowed', false)
        ->assertJsonPath('data.punch_out_correction_required', true)
        ->assertJsonPath('data.is_current_session', false);
});
