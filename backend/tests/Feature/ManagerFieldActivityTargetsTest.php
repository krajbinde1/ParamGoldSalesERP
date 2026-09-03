<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Targets\SaveMonthlyTarget;
use App\Enums\UserRole;
use App\Models\Collection;
use App\Models\Dealer;
use App\Models\FieldActivity;
use App\Models\Order;
use App\Models\WeeklyTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'Asia/Kolkata'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function fieldActivityTargetEmployee(UserRole $role, string $name, string $mobile): \App\Models\Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $name,
        'mobile' => $mobile,
        'email' => strtolower(str_replace(' ', '.', $name)).'.'.$mobile.'@example.com',
        'department' => 'Sales',
        'designation' => $role->label(),
        'joining_date' => '2026-07-01',
        'salary' => 25000,
        'base_location' => 'Aurangabad',
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
    ])->employee;
}

it('lets a manager see field activity targets only for direct reports using employee_id', function () {
    \App\Models\User::query()->create([
        'name' => 'Padding User',
        'email' => 'padding.fat.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
    ]);

    $manager = fieldActivityTargetEmployee(UserRole::Manager, 'Team Manager', '9500000001');
    $otherManager = fieldActivityTargetEmployee(UserRole::Manager, 'Other Manager', '9500000002');
    $report = fieldActivityTargetEmployee(UserRole::Employee, 'Ganesh Dere', '9500000003');
    $foreign = fieldActivityTargetEmployee(UserRole::Employee, 'Foreign Sales', '9500000004');
    $report->update(['reporting_manager_id' => $manager->id]);
    $foreign->update(['reporting_manager_id' => $otherManager->id]);

    expect($manager->user->id)->not->toBe($manager->id)
        ->and($report->user->id)->not->toBe($report->id);

    WeeklyTarget::query()->create([
        'employee_id' => $report->id,
        'week_start_date' => '2026-08-11',
        'week_end_date' => '2026-08-16',
        'sales_target' => 0,
        'collection_target' => 0,
        'field_activity_target' => 50,
        'status' => 'active',
        'remark' => 'August field visits',
    ]);
    WeeklyTarget::query()->create([
        'employee_id' => $foreign->id,
        'week_start_date' => '2026-08-11',
        'week_end_date' => '2026-08-16',
        'sales_target' => 0,
        'collection_target' => 0,
        'field_activity_target' => 80,
        'status' => 'active',
    ]);

    foreach (range(1, 32) as $i) {
        FieldActivity::query()->create([
            'employee_id' => $report->id,
            'farmer_name' => 'Farmer '.$i,
            'village' => 'Waluj',
            'taluka' => 'Gangapur',
            'activity_date' => '2026-08-12',
            'activity_time' => '10:00:00',
            'photo_path' => 'field-activities/report-'.$i.'.jpg',
            'status' => FieldActivity::STATUS_COMPLETED,
        ]);
    }

    FieldActivity::query()->create([
        'employee_id' => $foreign->id,
        'farmer_name' => 'Hidden Farmer',
        'village' => 'Pune',
        'taluka' => 'Haveli',
        'activity_date' => '2026-08-12',
        'activity_time' => '11:00:00',
        'photo_path' => 'field-activities/foreign.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);

    $this->actingAs($manager->user)
        ->getJson('/api/manager/targets?period=month')
        ->assertOk()
        ->assertJsonPath('summary.field_activity_target', 50)
        ->assertJsonPath('summary.field_activity_achieved', 32)
        ->assertJsonPath('summary.field_activity_remaining', 18)
        ->assertJsonPath('summary.field_activity_percentage', 64)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employee_id', $report->id)
        ->assertJsonPath('data.0.employee_name', 'Ganesh Dere')
        ->assertJsonPath('data.0.field_activity_target', 50)
        ->assertJsonPath('data.0.field_activity_achieved', 32)
        ->assertJsonPath('data.0.field_activity_remaining', 18)
        ->assertJsonPath('data.0.field_activity_percentage', 64);

    $this->actingAs($manager->user)
        ->getJson('/api/manager/employees/'.$report->id.'?period=month')
        ->assertOk()
        ->assertJsonPath('performance.field_activity_target', 50)
        ->assertJsonPath('performance.field_activity_achieved', 32)
        ->assertJsonPath('performance.field_activity_remaining', 18)
        ->assertJsonPath('performance.field_activity_percentage', 64);

    $this->actingAs($manager->user)
        ->getJson('/api/manager/employees/'.$foreign->id.'?period=month')
        ->assertForbidden();
});

it('returns zero field activity percentage when target is zero', function () {
    $manager = fieldActivityTargetEmployee(UserRole::Manager, 'Zero Manager', '9500000011');
    $report = fieldActivityTargetEmployee(UserRole::Employee, 'Zero Sales', '9500000012');
    $report->update(['reporting_manager_id' => $manager->id]);

    WeeklyTarget::query()->create([
        'employee_id' => $report->id,
        'week_start_date' => '2026-08-11',
        'week_end_date' => '2026-08-16',
        'sales_target' => 0,
        'collection_target' => 0,
        'field_activity_target' => 0,
        'status' => 'active',
    ]);

    FieldActivity::query()->create([
        'employee_id' => $report->id,
        'farmer_name' => 'Farmer',
        'village' => 'Waluj',
        'taluka' => 'Gangapur',
        'activity_date' => '2026-08-12',
        'activity_time' => '10:00:00',
        'photo_path' => 'field-activities/zero.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);

    $this->actingAs($report->user, 'sanctum')
        ->getJson('/api/employee/dashboard?period=month')
        ->assertOk()
        ->assertJsonPath('field_activity_target', 0)
        ->assertJsonPath('field_activity_achieved', 1)
        ->assertJsonPath('field_activity_remaining', 0)
        ->assertJsonPath('field_activity_percentage', null);
});

it('defaults manager targets to this week auto-generated weekly splits for every report', function () {
    $manager = fieldActivityTargetEmployee(UserRole::Manager, 'Week Manager', '9500000021');
    $report = fieldActivityTargetEmployee(UserRole::Employee, 'Week Report', '9500000022');
    $report->update(['reporting_manager_id' => $manager->id]);

    $dealer = Dealer::query()->create([
        'firm_name' => 'Manager Week Dealer',
        'owner_name' => 'Owner',
        'mobile' => '9888888'.random_int(100, 999),
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'village' => 'Test Village',
        'status' => true,
        'outstanding' => 0,
    ]);

    $monthly = app(SaveMonthlyTarget::class)->execute([
        'employee_id' => $report->id,
        'month_start_date' => '2026-08-01',
        'sales_target' => 310000,
        'collection_target' => 155000,
        'field_activity_target' => 31,
        'status' => 'active',
    ]);

    $thisWeek = $monthly->weeklyTargets()
        ->whereDate('week_start_date', '2026-08-17')
        ->whereDate('week_end_date', '2026-08-23')
        ->first();

    expect($thisWeek)->not->toBeNull();

    Order::query()->create([
        'order_no' => 'ORD-MGR-WEEK',
        'order_date' => '2026-08-20',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $report->id,
        'status' => Order::STATUS_DISPATCHED,
        'payment_type' => 'Credit',
        'subtotal' => 7000,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 7000,
        'updated_at' => '2026-08-20 10:00:00',
    ]);
    Collection::query()->create([
        'collection_date' => '2026-08-18',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $report->id,
        'amount' => 1800,
        'status' => Collection::STATUS_RECEIVED,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-MGR-WEEK',
    ]);
    FieldActivity::query()->create([
        'employee_id' => $report->id,
        'farmer_name' => 'Manager Week Farmer',
        'village' => 'Waluj',
        'taluka' => 'Gangapur',
        'activity_date' => '2026-08-19',
        'activity_time' => '10:00:00',
        'photo_path' => 'field-activities/manager-week.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);

    $weekJson = $this->actingAs($manager->user)
        ->getJson('/api/manager/targets')
        ->assertOk()
        ->assertJsonPath('period', 'This Week')
        ->assertJsonPath('period_key', 'week')
        ->assertJsonPath('start_date', '2026-08-17')
        ->assertJsonPath('end_date', '2026-08-20')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employee_id', $report->id)
        ->assertJsonPath('data.0.sales_achieved', 7000)
        ->assertJsonPath('data.0.collection_achieved', 1800)
        ->assertJsonPath('data.0.field_activity_target', (int) $thisWeek->field_activity_target)
        ->assertJsonPath('data.0.field_activity_achieved', 1)
        ->assertJsonPath('summary.sales_achieved', 7000);

    expect((float) $weekJson->json('data.0.sales_target'))->toBe((float) $thisWeek->sales_target)
        ->and((float) $weekJson->json('data.0.collection_target'))->toBe((float) $thisWeek->collection_target)
        ->and((float) $weekJson->json('summary.sales_target'))->toBe((float) $thisWeek->sales_target);

    $employeesJson = $this->actingAs($manager->user)
        ->getJson('/api/manager/employees')
        ->assertOk()
        ->assertJsonPath('period_key', 'week');

    expect((float) $employeesJson->json('data.0.sales_target'))->toBe((float) $thisWeek->sales_target)
        ->and((float) $employeesJson->json('data.0.sales_percentage'))->toBe(
            round((7000 / (float) $thisWeek->sales_target) * 100, 2)
        );

    $this->actingAs($manager->user)
        ->getJson('/api/manager/targets?period=month')
        ->assertOk()
        ->assertJsonPath('period', 'This Month')
        ->assertJsonPath('data.0.sales_target', 310000)
        ->assertJsonPath('summary.sales_target', 310000)
        ->assertJsonPath('summary.sales_achieved', 7000);
});
