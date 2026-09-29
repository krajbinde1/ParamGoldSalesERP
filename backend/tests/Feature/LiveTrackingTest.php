<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeRoutePoint;
use App\Models\User;
use App\Services\EmployeeRouteAnalysisService;
use App\Services\LiveTrackingService;
use App\Support\AttendanceCalendar;
use App\Support\LiveTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-29 11:00:00', AttendanceCalendar::TIMEZONE));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function liveEmployee(string $mobile, string $role = 'employee'): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => 'Live '.$mobile,
        'mobile' => $mobile,
        'email' => $mobile.'.live@example.com',
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
        'account_number' => str_pad($mobile, 12, '4', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => $role,
    ])->employee->refresh();
}

function liveDirector(): User
{
    return User::query()->create([
        'name' => 'Live Director',
        'email' => 'live.director.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

function liveAttendance(Employee $employee, array $extra = []): Attendance
{
    return Attendance::query()->create(array_merge([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-09-29',
        'punch_in_time' => '09:00:00',
        'attendance_status' => 'Punched In',
        'approval_status' => 'Pending',
        'punch_in_latitude' => 18.5204000,
        'punch_in_longitude' => 73.8567000,
        'punch_in_location' => 'Pune',
        'total_route_distance_km' => 4.2,
    ], $extra));
}

function livePoint(Attendance $attendance, float $lat, float $lng, Carbon $recordedAt): EmployeeRoutePoint
{
    return EmployeeRoutePoint::query()->create([
        'attendance_id' => $attendance->id,
        'employee_id' => $attendance->employee_id,
        'local_uuid' => (string) Str::uuid(),
        'latitude' => $lat,
        'longitude' => $lng,
        'accuracy' => 12,
        'recorded_at' => $recordedAt,
        'source' => 'test',
    ]);
}

it('shows a punched-in employee after the first route point and moves the marker on the next point', function (): void {
    $director = liveDirector();
    $employee = liveEmployee('9710000001');
    $attendance = liveAttendance($employee);
    $now = AttendanceCalendar::now();

    expect(app(LiveTrackingService::class)->snapshot($director)['employees'])->toHaveCount(1)
        ->and(app(LiveTrackingService::class)->snapshot($director)['employees'][0]['latitude'])->toBeNull()
        ->and(app(LiveTrackingService::class)->snapshot($director)['employees'][0]['status'])->toBe(LiveTracking::STATUS_NO_GPS)
        ->and(app(LiveTrackingService::class)->snapshot($director)['employees'][0]['gps_status'])->toBe('no_gps')
        ->and(app(LiveTrackingService::class)->snapshot($director)['summary']['active_employees'])->toBe(1)
        ->and(app(LiveTrackingService::class)->snapshot($director)['summary']['punch_in_today'])->toBe(1);

    livePoint($attendance, 18.5204, 73.8567, $now->copy()->subMinutes(2));

    $first = app(LiveTrackingService::class)->snapshot($director)['employees'][0];
    expect($first['employee_id'])->toBe($employee->id)
        ->and($first['latitude'])->toBe(18.5204)
        ->and($first['status'])->toBe(LiveTracking::STATUS_STOPPED)
        ->and($first['today_distance_km'])->toBe(4.2);

    livePoint($attendance, 18.5222, 73.8567, $now->copy()->subMinute());

    $second = app(LiveTrackingService::class)->snapshot($director)['employees'][0];
    expect($second['latitude'])->toBe(18.5222)
        ->and($second['status'])->toBe(LiveTracking::STATUS_MOVING);
});

it('marks a stationary employee stopped and an old fix delayed or offline', function (): void {
    $director = liveDirector();
    $now = AttendanceCalendar::now();
    $stopped = liveAttendance(liveEmployee('9710000002'));
    livePoint($stopped, 18.52040, 73.85670, $now->copy()->subMinutes(3));
    livePoint($stopped, 18.52045, 73.85670, $now->copy()->subMinute());

    $delayed = liveAttendance(liveEmployee('9710000003'));
    livePoint($delayed, 18.53, 73.86, $now->copy()->subMinutes(8));

    $offline = liveAttendance(liveEmployee('9710000004'));
    livePoint($offline, 18.54, 73.87, $now->copy()->subMinutes(20));

    $rows = collect(app(LiveTrackingService::class)->snapshot($director)['employees'])->keyBy('employee_id');

    expect($rows[$stopped->employee_id]['status'])->toBe(LiveTracking::STATUS_STOPPED)
        ->and($rows[$delayed->employee_id]['status'])->toBe(LiveTracking::STATUS_DELAYED)
        ->and($rows[$offline->employee_id]['status'])->toBe(LiveTracking::STATUS_OFFLINE)
        ->and($rows[$offline->employee_id]['latitude'])->not->toBeNull();
});

it('removes a punched-out employee from live tracking and keeps the historical route', function (): void {
    $director = liveDirector();
    $employee = liveEmployee('9710000005');
    $attendance = liveAttendance($employee);
    livePoint($attendance, 18.52, 73.85, AttendanceCalendar::now()->subMinutes(2));

    $attendance->update(['punch_out_time' => '10:45:00']);

    $snapshot = app(LiveTrackingService::class)->snapshot($director);
    expect(collect($snapshot['employees'])->pluck('employee_id'))->not->toContain($employee->id)
        ->and(EmployeeRoutePoint::query()->where('attendance_id', $attendance->id)->count())->toBe(1);

    $analysis = app(EmployeeRouteAnalysisService::class)->analyze($attendance->fresh());
    expect($analysis['diagnostics']['total_points'])->toBe(1);
});

it('shows every active employee to a director and only direct reports to a manager', function (): void {
    $director = liveDirector();
    $manager = liveEmployee('9710000006', UserRole::Manager->value);
    $team = liveEmployee('9710000007');
    $other = liveEmployee('9710000008');
    $team->update(['reporting_manager_id' => $manager->id]);

    livePoint(liveAttendance($team), 18.52, 73.85, AttendanceCalendar::now()->subMinute());
    livePoint(liveAttendance($other), 18.53, 73.86, AttendanceCalendar::now()->subMinute());

    $service = app(LiveTrackingService::class);
    $directorIds = collect($service->snapshot($director)['employees'])->pluck('employee_id');
    $managerIds = collect($service->snapshot($manager->user)['employees'])->pluck('employee_id');

    expect($directorIds)->toContain($team->id)
        ->and($directorIds)->toContain($other->id)
        ->and($managerIds->all())->toBe([$team->id]);

    $this->actingAs($other->user, 'sanctum')
        ->getJson('/api/admin/live-tracking')
        ->assertForbidden();
});

it('returns only route points newer than after_point_id', function (): void {
    $director = liveDirector();
    $employee = liveEmployee('9710000009');
    $attendance = liveAttendance($employee);
    $now = AttendanceCalendar::now();
    $first = livePoint($attendance, 18.5204, 73.8567, $now->copy()->subMinutes(4));
    livePoint($attendance, 18.5222, 73.8567, $now->copy()->subMinute());

    $this->actingAs($director, 'sanctum')
        ->getJson('/api/admin/live-tracking/'.$employee->id.'/route?after_point_id='.$first->id)
        ->assertOk()
        ->assertJsonCount(1, 'points')
        ->assertJsonPath('points.0.latitude', 18.5222);

    $full = $this->actingAs($director, 'sanctum')
        ->getJson('/api/admin/live-tracking/'.$employee->id.'/route')
        ->assertOk()
        ->json();

    expect($full['points'])->toHaveCount(2)
        ->and($full)->not->toHaveKey('route_points');
});

it('includes employees with and without GPS in the same snapshot', function (): void {
    $director = liveDirector();
    $withGps = liveAttendance(liveEmployee('9710000018'));
    $withoutGps = liveAttendance(liveEmployee('9710000019'));
    livePoint($withGps, 18.5204, 73.8567, AttendanceCalendar::now()->subMinute());

    $rows = collect(app(LiveTrackingService::class)->snapshot($director)['employees'])->keyBy('employee_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$withGps->employee_id]['latitude'])->toEqual(18.5204)
        ->and($rows[$withoutGps->employee_id]['status'])->toBe(LiveTracking::STATUS_NO_GPS)
        ->and($rows[$withoutGps->employee_id]['latitude'])->toBeNull();
});

it('keeps four punched-in employees with no GPS and ignores yesterday', function (): void {
    $director = liveDirector();
    foreach (['9710000011', '9710000012', '9710000013', '9710000014'] as $mobile) {
        liveAttendance(liveEmployee($mobile));
    }
    $yesterday = liveAttendance(liveEmployee('9710000015'), ['attendance_date' => '2026-09-28']);
    livePoint($yesterday, 18.52, 73.85, Carbon::parse('2026-09-28 18:00:00', AttendanceCalendar::TIMEZONE));

    $snapshot = app(LiveTrackingService::class)->snapshot($director);

    expect($snapshot['summary']['active_employees'])->toBe(4)
        ->and($snapshot['summary']['punch_in_today'])->toBe(4)
        ->and($snapshot['summary']['no_gps'])->toBe(4)
        ->and(collect($snapshot['employees'])->every(fn (array $row): bool => $row['gps_status'] === 'no_gps'))->toBeTrue()
        ->and(collect($snapshot['employees'])->pluck('employee_id'))->not->toContain($yesterday->employee_id);
});

it('uses the Asia/Kolkata date so late evening still counts as today', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-29 23:40:00', AttendanceCalendar::TIMEZONE));
    $director = liveDirector();
    $today = liveAttendance(liveEmployee('9710000016'));
    liveAttendance(liveEmployee('9710000017'), ['attendance_date' => '2026-09-30']);

    $ids = collect(app(LiveTrackingService::class)->snapshot($director)['employees'])->pluck('employee_id');

    expect($ids->all())->toBe([$today->employee_id]);
});
