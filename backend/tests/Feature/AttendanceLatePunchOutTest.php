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
