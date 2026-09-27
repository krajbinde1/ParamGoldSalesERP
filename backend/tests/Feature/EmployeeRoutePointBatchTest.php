<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeRoutePoint;
use App\Services\RouteDistanceCalculator;
use App\Support\AttendanceCalendar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-26 10:30:00', AttendanceCalendar::TIMEZONE));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function routeBatchEmployee(string $mobile): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => 'Route Batch '.$mobile,
        'mobile' => $mobile,
        'email' => $mobile.'@example.com',
        'department' => 'Sales',
        'designation' => 'Sales Executive',
        'joining_date' => '2026-01-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 300,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => '2'.str_pad(substr($mobile, -11), 11, '0', STR_PAD_LEFT),
        'pan_number' => 'ABCDE'.substr($mobile, -4).'F',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad($mobile, 12, '3', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => UserRole::Employee->value,
    ])->employee->refresh();
}

function routeBatchAttendance(Employee $employee, array $extra = []): Attendance
{
    return Attendance::query()->create(array_merge([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-26',
        'punch_in_time' => '09:00:00',
        'attendance_status' => 'Punched In',
        'approval_status' => 'Pending',
        'punch_in_latitude' => 18.5204000,
        'punch_in_longitude' => 73.8567000,
    ], $extra));
}

function routePointPayload(string $uuid, float $lat, float $lng, string $recordedAt = '2026-09-26T10:15:00+05:30'): array
{
    return [
        'local_uuid' => $uuid,
        'latitude' => $lat,
        'longitude' => $lng,
        'accuracy' => 10,
        'recorded_at' => $recordedAt,
        'source' => 'test',
    ];
}

it('rejects more than 500 points in one API request', function (): void {
    $employee = routeBatchEmployee('9610000001');
    $attendance = routeBatchAttendance($employee);
    $points = [];
    for ($i = 0; $i < 501; $i++) {
        $points[] = routePointPayload((string) Str::uuid(), 18.5204, 73.8567);
    }

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/route-points/batch', [
            'attendance_id' => $attendance->id,
            'points' => $points,
        ])
        ->assertStatus(422);
});

it('is idempotent for the same local_uuid and never inserts a duplicate row', function (): void {
    $employee = routeBatchEmployee('9610000002');
    $attendance = routeBatchAttendance($employee);
    $uuid = (string) Str::uuid();
    $payload = [
        'attendance_id' => $attendance->id,
        'points' => [routePointPayload($uuid, 18.5204, 73.8567)],
    ];

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/route-points/batch', $payload)
        ->assertCreated()
        ->assertJsonPath('inserted', 1)
        ->assertJsonPath('skipped', 0);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/route-points/batch', $payload)
        ->assertCreated()
        ->assertJsonPath('inserted', 0)
        ->assertJsonPath('skipped', 1);

    expect(EmployeeRoutePoint::query()->where('local_uuid', $uuid)->count())->toBe(1);

    expect(fn () => EmployeeRoutePoint::query()->create([
        'attendance_id' => $attendance->id,
        'employee_id' => $employee->id,
        'local_uuid' => $uuid,
        'latitude' => 18.5300,
        'longitude' => 73.8600,
        'recorded_at' => now(),
        'source' => 'dup',
    ]))->toThrow(QueryException::class);
});

it('records 25m+ movement and keeps kilometre calculation accurate', function (): void {
    $employee = routeBatchEmployee('9610000003');
    $attendance = routeBatchAttendance($employee);
    $originLat = 18.5204000;
    $originLng = 73.8567000;
    $step = 25 / 111320;
    $uuidA = (string) Str::uuid();
    $uuidB = (string) Str::uuid();
    $uuidC = (string) Str::uuid();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/route-points/batch', [
            'attendance_id' => $attendance->id,
            'points' => [
                routePointPayload($uuidA, $originLat, $originLng, '2026-09-26T10:10:00+05:30'),
                routePointPayload($uuidB, $originLat + $step, $originLng, '2026-09-26T10:11:00+05:30'),
                routePointPayload($uuidC, $originLat + (2 * $step), $originLng, '2026-09-26T10:12:00+05:30'),
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('inserted', 3);

    $distance = (float) $attendance->fresh()->total_route_distance_km;
    expect($distance)->toBeGreaterThanOrEqual(0.04)
        ->and($distance)->toBeLessThanOrEqual(0.06);

    $calculator = app(RouteDistanceCalculator::class);
    $analysis = $calculator->calculate(EmployeeRoutePoint::query()->where('attendance_id', $attendance->id)->get());
    expect($analysis['total_distance_km'])->toEqual($distance)
        ->and($analysis['valid_point_count'])->toBe(3);
});

it('rejects route points when there is no punch-in session window', function (): void {
    $employee = routeBatchEmployee('9610000004');
    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-26',
        'attendance_status' => 'Absent',
        'approval_status' => 'Pending',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/route-points/batch', [
            'attendance_id' => $attendance->id,
            'points' => [routePointPayload((string) Str::uuid(), 18.5204, 73.8567)],
        ])
        ->assertStatus(422)
        ->assertJsonFragment(['Route points require an attendance record with punch-in.']);
});

it('still accepts a final batch after punch out within the closed-session grace window', function (): void {
    $employee = routeBatchEmployee('9610000005');
    $attendance = routeBatchAttendance($employee, [
        'punch_out_time' => '10:20:00',
        'punch_out_at' => Carbon::parse('2026-09-26 10:20:00', AttendanceCalendar::TIMEZONE),
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/route-points/batch', [
            'attendance_id' => $attendance->id,
            'points' => [routePointPayload(
                (string) Str::uuid(),
                18.5204,
                73.8567,
                '2026-09-26T10:21:00+05:30',
            )],
        ])
        ->assertCreated()
        ->assertJsonPath('inserted', 1);
});
