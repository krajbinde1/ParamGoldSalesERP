<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Models\Attendance;
use App\Models\EmployeeRoutePoint;
use App\Services\EmployeeRouteAnalysisService;
use App\Support\AttendanceCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function routeViewEmployee(array $overrides = []): \App\Models\Employee
{
    static $counter = 9600000000;
    $counter++;

    return app(CreateEmployeeWithUserAccount::class)->execute(array_merge([
        'full_name' => 'Route Viewer '.$counter,
        'mobile' => (string) $counter,
        'email' => "route.viewer.{$counter}@example.com",
        'department' => 'Sales',
        'designation' => 'Sales Executive',
        'joining_date' => '2026-07-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 300,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => '2'.str_pad((string) ($counter % 100000000000), 11, '0', STR_PAD_LEFT),
        'pan_number' => 'ABCDE'.str_pad((string) ($counter % 10000), 4, '0', STR_PAD_LEFT).'F',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad((string) $counter, 12, '2', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
    ], $overrides))->employee;
}

it('builds chronological journey events with numbered stoppages and travel rows', function () {
    $employee = routeViewEmployee();
    $date = Carbon::parse('2026-08-10', AttendanceCalendar::TIMEZONE)->startOfDay();

    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => $date->toDateString(),
        'punch_in_time' => '09:00:00',
        'punch_out_time' => '18:00:00',
        'punch_in_location' => 'Office Gate',
        'punch_out_location' => 'Home',
        'punch_in_latitude' => 18.5204000,
        'punch_in_longitude' => 73.8567000,
        'punch_out_latitude' => 18.5310000,
        'punch_out_longitude' => 73.8450000,
        'attendance_status' => 'Present',
        'approval_status' => 'Approved',
    ]);

    $points = [
        ['09:05:00', 18.5210000, 73.8570000],
        ['09:10:00', 18.5220000, 73.8580000],
        // Stoppage 1: stay within ~75m for >= 10 minutes, points > 5m apart
        ['09:20:00', 18.5300000, 73.8500000],
        ['09:25:00', 18.5300800, 73.8500000],
        ['09:30:00', 18.5300000, 73.8500800],
        ['09:35:00', 18.5300600, 73.8500600],
        // Travel
        ['10:00:00', 18.5400000, 73.8600000],
        ['10:10:00', 18.5450000, 73.8650000],
        // Stoppage 2
        ['10:30:00', 18.5500000, 73.8700000],
        ['10:40:00', 18.5500900, 73.8700000],
        ['10:50:00', 18.5500000, 73.8700900],
        ['11:00:00', 18.5500700, 73.8700700],
        // Travel to end
        ['17:30:00', 18.5350000, 73.8480000],
        ['17:50:00', 18.5320000, 73.8460000],
    ];

    foreach ($points as [$time, $lat, $lng]) {
        EmployeeRoutePoint::query()->create([
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'local_uuid' => (string) Str::uuid(),
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy' => 10,
            'recorded_at' => Carbon::parse($date->toDateString().' '.$time, AttendanceCalendar::TIMEZONE),
            'source' => 'test',
        ]);
    }

    $analysis = app(EmployeeRouteAnalysisService::class)->analyze($attendance->fresh());

    expect($analysis['summary']['total_distance_km'])->toBeGreaterThan(0)
        ->and($analysis['summary']['stop_count'])->toBeGreaterThanOrEqual(1)
        ->and($analysis['summary']['punch_in_time'])->toBe('09:00 AM')
        ->and($analysis['summary']['punch_out_time'])->toBe('06:00 PM')
        ->and($analysis['journey_events'])->not->toBeEmpty();

    $types = collect($analysis['journey_events'])->pluck('type')->all();

    expect($types[0])->toBe('start')
        ->and(collect($types)->contains('travel'))->toBeTrue()
        ->and(collect($types)->contains('stoppage'))->toBeTrue()
        ->and(end($types))->toBe('end');

    $stoppages = collect($analysis['journey_events'])->where('type', 'stoppage')->values();

    expect($stoppages->first()['sequence'])->toBe(1);

    if ($stoppages->count() > 1) {
        expect($stoppages[1]['sequence'])->toBe(2);
    }

    $validTimes = collect($analysis['valid_points'])
        ->map(fn (array $point) => $point['recorded_at']->timestamp)
        ->all();

    $sorted = $validTimes;
    sort($sorted);

    expect($validTimes)->toBe($sorted);
});
