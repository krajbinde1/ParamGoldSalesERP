<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Models\Attendance;
use App\Models\EmployeeRoutePoint;
use App\Models\TaDaClaim;
use App\Models\TaDaSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function taDaEmployee(array $overrides = []): \App\Models\Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute(array_merge([
        'full_name' => 'TA/DA Tester',
        'mobile' => '9123456789',
        'email' => 'tada.tester@example.com',
        'department' => 'Sales',
        'designation' => 'Sales Executive',
        'joining_date' => '2026-07-01',
        'salary' => 25000,
        'base_location' => 'Aurangabad',
        'daily_allowance' => 300,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => '234567890124',
        'pan_number' => 'ABCDE1235F',
        'bank_name' => 'Test Bank',
        'account_number' => '123456789013',
        'ifsc_code' => 'TEST0123457',
        'status' => true,
    ], $overrides))->employee;
}

function seedRouteForAttendance(Attendance $attendance): void
{
    $baseLat = 19.8762;
    $baseLng = 75.3433;

    for ($index = 0; $index < 5; $index++) {
        EmployeeRoutePoint::query()->create([
            'attendance_id' => $attendance->id,
            'employee_id' => $attendance->employee_id,
            'latitude' => $baseLat + ($index * 0.001),
            'longitude' => $baseLng + ($index * 0.001),
            'accuracy' => 8,
            'speed' => 12,
            'recorded_at' => now('Asia/Kolkata')->subMinutes(30 - ($index * 2)),
            'source' => 'test',
        ]);
    }
}

it('does not create claims when read-only TA/DA endpoints are called', function () {
    Storage::fake('public');

    $employee = taDaEmployee();
    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-07-15',
        'punch_in_time' => '09:00',
        'punch_out_time' => '18:00',
        'attendance_status' => 'Present',
        'approval_status' => 'Pending',
    ]);
    seedRouteForAttendance($attendance);

    TaDaSetting::query()->create([
        'per_km_rate' => 5.00,
        'is_active' => true,
    ]);

    $this->actingAs($employee->user, 'sanctum');

    expect(TaDaClaim::count())->toBe(0);

    $this->getJson('/api/employee/ta-da-claims')->assertOk();
    $this->getJson('/api/employee/ta-da-claims/calendar?month=7&year=2026')->assertOk();
    $this->getJson('/api/employee/ta-da-rate')->assertOk();
    $this->getJson('/api/employee/ta-da-claims/travel-summary?claim_date=2026-07-15')->assertOk();

    expect(TaDaClaim::count())->toBe(0);
});

it('creates a claim only through explicit authenticated POST submit', function () {
    Storage::fake('public');

    $employee = taDaEmployee();
    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-07-16',
        'punch_in_time' => '09:00',
        'punch_out_time' => '18:00',
        'attendance_status' => 'Present',
        'approval_status' => 'Pending',
    ]);
    seedRouteForAttendance($attendance);

    TaDaSetting::query()->create([
        'per_km_rate' => 5.00,
        'is_active' => true,
    ]);

    $this->actingAs($employee->user, 'sanctum');

    expect(TaDaClaim::count())->toBe(0);

    $response = $this->postJson('/api/employee/ta-da-claims', [
        'claim_date' => '2026-07-16',
        'from_location' => 'Office',
        'to_location' => 'Field',
        'da_amount' => 100,
        'other_expense' => 25,
        'employee_remarks' => 'Manual submit test',
        'photo' => UploadedFile::fake()->image('bill.jpg'),
    ]);

    $response->assertCreated();
    expect(TaDaClaim::count())->toBe(1);

    $claim = TaDaClaim::first();
    expect($claim->status)->toBe(TaDaClaim::STATUS_PENDING);
    expect($claim->employee_remarks)->toBe('Manual submit test');

    $this->getJson('/api/employee/ta-da-claims')->assertOk();
    expect(TaDaClaim::count())->toBe(1);
});

it('rejects duplicate claims for the same employee and claim date', function () {
    Storage::fake('public');

    $employee = taDaEmployee();
    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-07-17',
        'punch_in_time' => '09:00',
        'punch_out_time' => '18:00',
        'attendance_status' => 'Present',
        'approval_status' => 'Pending',
    ]);
    seedRouteForAttendance($attendance);

    TaDaSetting::query()->create([
        'per_km_rate' => 5.00,
        'is_active' => true,
    ]);

    $this->actingAs($employee->user, 'sanctum');

    $payload = [
        'claim_date' => '2026-07-17',
        'from_location' => 'Office',
        'to_location' => 'Field',
        'da_amount' => 0,
        'other_expense' => 0,
        'photo' => UploadedFile::fake()->image('bill.jpg'),
    ];

    $this->postJson('/api/employee/ta-da-claims', $payload)->assertCreated();
    $this->postJson('/api/employee/ta-da-claims', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['claim_date']);

    expect(TaDaClaim::count())->toBe(1);
});

it('rejects non-POST methods for claim creation endpoint', function () {
    $employee = taDaEmployee();
    $this->actingAs($employee->user, 'sanctum');

    expect(TaDaClaim::count())->toBe(0);

    $this->getJson('/api/employee/ta-da-claims/travel-summary?claim_date=2026-07-15')
        ->assertStatus(422);

    expect(TaDaClaim::count())->toBe(0);
});
