<?php

use App\Enums\UserRole;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\DealerVisits\Pages\ListDealerVisits;
use App\Filament\Resources\FieldActivities\Pages\ListFieldActivities;
use App\Filament\Widgets\AdminDirectorWelcomeWidget;
use App\Models\Attendance;
use App\Models\Dealer;
use App\Models\DealerVisit;
use App\Models\Employee;
use App\Models\FieldActivity;
use App\Models\User;
use App\Services\Attendance\AttendanceStatusCalculator;
use App\Services\Dashboard\AdminDashboardDataService;
use App\Support\AttendanceCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21 10:30:00', AttendanceCalendar::TIMEZONE));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function teamTodayEmployee(string $name, string $mobile): Employee
{
    static $n = 100;
    $n++;

    return Employee::query()->create([
        'full_name' => $name,
        'mobile' => $mobile,
        'department' => 'Sales',
        'designation' => 'Executive',
        'joining_date' => '2026-01-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 0,
        'travel_allowance' => 0,
        'aadhaar_number' => str_pad((string) (300000000000 + $n), 12, '0', STR_PAD_LEFT),
        'pan_number' => 'TTTTT'.str_pad((string) $n, 4, '0', STR_PAD_LEFT).'Z',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad((string) (200000000000 + $n), 12, '0', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
    ]);
}

function teamTodayAdmin(): User
{
    return User::query()->create([
        'name' => 'Team Today Admin',
        'email' => 'team.today.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

function teamTodayDealerVisit(int $employeeId, int $dealerId, string $date, string $photo): DealerVisit
{
    return DealerVisit::query()->create([
        'employee_id' => $employeeId,
        'dealer_id' => $dealerId,
        'visit_date' => $date,
        'visit_time' => '10:15:00',
        'photo_path' => $photo,
        'latitude' => 19.8765432,
        'longitude' => 75.3432109,
        'accuracy' => 8.5,
        'location_captured_at' => $date.' 10:15:00',
        'status' => DealerVisit::STATUS_COMPLETED,
    ]);
}

function teamTodayDealer(string $firm = 'Team Today Dealer'): Dealer
{
    return Dealer::query()->create([
        'firm_name' => $firm,
        'owner_name' => 'Owner',
        'mobile' => '9777777'.random_int(100, 999),
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'village' => 'Test Village',
        'status' => true,
        'outstanding' => 0,
    ]);
}

it('counts punched in from punch time not present status and keeps other today metrics', function (): void {
    $today = AttendanceCalendar::today()->toDateString();
    $yesterday = AttendanceCalendar::today()->copy()->subDay()->toDateString();

    $openPunch = teamTodayEmployee('Open Punch', '9810000001');
    $present = teamTodayEmployee('Full Present', '9810000002');
    $halfDay = teamTodayEmployee('Half Day Emp', '9810000003');
    $shortDay = teamTodayEmployee('Short Punch', '9810000004');
    $absentNoPunch = teamTodayEmployee('No Punch Absent', '9810000005');
    $yesterdayPresent = teamTodayEmployee('Yesterday Present', '9810000006');

    Attendance::query()->create([
        'employee_id' => $openPunch->id,
        'attendance_date' => $today,
        'punch_in_time' => '09:00:00',
        'approval_status' => 'Pending',
    ]);
    Attendance::query()->create([
        'employee_id' => $present->id,
        'attendance_date' => $today,
        'punch_in_time' => '09:00:00',
        'punch_out_time' => '17:00:00',
        'approval_status' => 'Pending',
    ]);
    Attendance::query()->create([
        'employee_id' => $halfDay->id,
        'attendance_date' => $today,
        'punch_in_time' => '09:00:00',
        'punch_out_time' => '14:00:00',
        'approval_status' => 'Pending',
    ]);
    Attendance::query()->create([
        'employee_id' => $shortDay->id,
        'attendance_date' => $today,
        'punch_in_time' => '09:00:00',
        'punch_out_time' => '11:00:00',
        'approval_status' => 'Pending',
    ]);
    Attendance::query()->create([
        'employee_id' => $absentNoPunch->id,
        'attendance_date' => $today,
        'attendance_status' => AttendanceStatusCalculator::STATUS_ABSENT,
        'approval_status' => 'Pending',
    ]);
    Attendance::query()->create([
        'employee_id' => $yesterdayPresent->id,
        'attendance_date' => $yesterday,
        'punch_in_time' => '09:00:00',
        'punch_out_time' => '17:00:00',
        'approval_status' => 'Pending',
    ]);

    $dealer = teamTodayDealer();
    teamTodayDealerVisit($present->id, $dealer->id, $today, 'dealer-visits/today.jpg');
    teamTodayDealerVisit($present->id, $dealer->id, $yesterday, 'dealer-visits/yesterday.jpg');
    FieldActivity::query()->create([
        'employee_id' => $present->id,
        'farmer_name' => 'Today Farmer',
        'village' => 'Waluj',
        'taluka' => 'Gangapur',
        'activity_date' => $today,
        'activity_time' => '11:40:00',
        'photo_path' => 'field-activities/today.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);
    FieldActivity::query()->create([
        'employee_id' => $present->id,
        'farmer_name' => 'Yesterday Farmer',
        'village' => 'Waluj',
        'taluka' => 'Gangapur',
        'activity_date' => $yesterday,
        'activity_time' => '12:00:00',
        'photo_path' => 'field-activities/yesterday.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $counts = app(AdminDashboardDataService::class)->teamTodayCounts();

    expect($counts['today'])->toBe($today)
        ->and($counts['punched_in'])->toBe(4)
        ->and($counts['present'])->toBe(1)
        ->and($counts['half_day'])->toBe(1)
        ->and($counts['absent'])->toBe(2)
        ->and($counts['dealer_visits'])->toBe(1)
        ->and($counts['field_visits'])->toBe(1)
        ->and($queries)->toBe(3);
});

it('renders clickable director KPI cards in the expected order', function (): void {
    $admin = teamTodayAdmin();
    $today = AttendanceCalendar::today()->toDateString();

    Livewire::actingAs($admin)
        ->test(AdminDirectorWelcomeWidget::class)
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Today Sales',
            'Today Collection',
            'Team Punch In',
            'Dealer Visits Today',
            'Pending Orders',
            'Payment Approval',
        ])
        ->assertSeeHtml('filters%5Border_date%5D%5Bdate%5D='.$today)
        ->assertSeeHtml('filters%5Bcollection_date%5D%5Bdate%5D='.$today)
        ->assertSeeHtml('filters%5Bpunched_in%5D%5BisActive%5D=1')
        ->assertSeeHtml('filters%5Bvisit_date%5D%5Bdate%5D='.$today)
        ->assertSeeHtml('filters%5Baction_required%5D%5BisActive%5D=1');
});

it('filters today attendance to unique punched-in employees from the dashboard card', function (): void {
    $admin = teamTodayAdmin();
    $today = AttendanceCalendar::today()->toDateString();

    $punchedIn = teamTodayEmployee('Dashboard Punched In', '9820000001');
    $present = teamTodayEmployee('Dashboard Present', '9820000002');
    $absent = teamTodayEmployee('Dashboard Absent', '9820000003');

    $openPunch = Attendance::query()->create([
        'employee_id' => $punchedIn->id,
        'attendance_date' => $today,
        'punch_in_time' => '09:15:00',
        'approval_status' => 'Pending',
    ]);
    $presentRecord = Attendance::query()->create([
        'employee_id' => $present->id,
        'attendance_date' => $today,
        'punch_in_time' => '09:00:00',
        'punch_out_time' => '18:00:00',
        'approval_status' => 'Pending',
    ]);
    $absentRecord = Attendance::query()->create([
        'employee_id' => $absent->id,
        'attendance_date' => $today,
        'attendance_status' => AttendanceStatusCalculator::STATUS_ABSENT,
        'approval_status' => 'Pending',
    ]);

    Livewire::actingAs($admin)
        ->test(ListAttendances::class)
        ->filterTable('punched_in')
        ->assertCanSeeTableRecords([$openPunch, $presentRecord])
        ->assertCanNotSeeTableRecords([$absentRecord])
        ->assertCountTableRecords(2);

    Livewire::actingAs($admin)
        ->test(ListAttendances::class)
        ->filterTable('attendance_status', AttendanceStatusCalculator::STATUS_PRESENT)
        ->assertCanSeeTableRecords([$presentRecord])
        ->assertCanNotSeeTableRecords([$openPunch, $absentRecord])
        ->assertCountTableRecords(1);
});

it('filters dealer visits and field activities to today from dashboard cards', function (): void {
    $admin = teamTodayAdmin();
    $today = AttendanceCalendar::today()->toDateString();
    $yesterday = AttendanceCalendar::today()->copy()->subDay()->toDateString();
    $employee = teamTodayEmployee('Visit Employee', '9830000001');
    $todayDealer = teamTodayDealer();
    $yesterdayDealer = teamTodayDealer('Yesterday Only Dealer');

    $todayVisit = teamTodayDealerVisit($employee->id, $todayDealer->id, $today, 'dealer-visits/today.jpg');
    $yesterdayVisit = teamTodayDealerVisit($employee->id, $yesterdayDealer->id, $yesterday, 'dealer-visits/yesterday.jpg');
    $todayActivity = FieldActivity::query()->create([
        'employee_id' => $employee->id,
        'farmer_name' => 'Today Field Farmer',
        'village' => 'Waluj',
        'taluka' => 'Gangapur',
        'activity_date' => $today,
        'activity_time' => '12:00:00',
        'photo_path' => 'field-activities/today.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);
    $yesterdayActivity = FieldActivity::query()->create([
        'employee_id' => $employee->id,
        'farmer_name' => 'Yesterday Field Farmer',
        'village' => 'Waluj',
        'taluka' => 'Gangapur',
        'activity_date' => $yesterday,
        'activity_time' => '13:00:00',
        'photo_path' => 'field-activities/yesterday.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);

    Livewire::actingAs($admin)
        ->test(ListDealerVisits::class)
        ->filterTable('visit_date', ['date' => $today])
        ->assertCanSeeTableRecords([$todayVisit])
        ->assertCanNotSeeTableRecords([$yesterdayVisit])
        ->assertCountTableRecords(1);

    Livewire::actingAs($admin)
        ->test(ListFieldActivities::class)
        ->filterTable('activity_date', ['date' => $today])
        ->assertCanSeeTableRecords([$todayActivity])
        ->assertCanNotSeeTableRecords([$yesterdayActivity])
        ->assertCountTableRecords(1);
});

it('still finds today dealer visits when the date filter contains a datetime', function (): void {
    $admin = teamTodayAdmin();
    $today = AttendanceCalendar::today()->toDateString();
    $yesterday = AttendanceCalendar::today()->copy()->subDay()->toDateString();
    $employee = teamTodayEmployee('Datetime Filter Employee', '9840000001');
    $todayDealer = teamTodayDealer('Datetime Today Dealer');
    $yesterdayDealer = teamTodayDealer('Datetime Yesterday Dealer');

    $todayVisit = teamTodayDealerVisit($employee->id, $todayDealer->id, $today, 'dealer-visits/datetime-today.jpg');
    $yesterdayVisit = teamTodayDealerVisit($employee->id, $yesterdayDealer->id, $yesterday, 'dealer-visits/datetime-yesterday.jpg');

    expect(\App\Filament\Support\TodayDateFilter::normalizeDate('2026-08-21 16:06:57'))->toBe('2026-08-21')
        ->and(app(AdminDashboardDataService::class)->teamTodayCounts()['dealer_visits'])->toBe(1);

    Livewire::actingAs($admin)
        ->test(ListDealerVisits::class)
        ->filterTable('visit_date', ['date' => '2026-08-21 16:06:57'])
        ->assertCanSeeTableRecords([$todayVisit])
        ->assertCanNotSeeTableRecords([$yesterdayVisit])
        ->assertCountTableRecords(1);
});
