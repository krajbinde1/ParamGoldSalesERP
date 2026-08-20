<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\FieldActivity;
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
        ->getJson('/api/employee/dashboard')
        ->assertOk()
        ->assertJsonPath('field_activity_target', 0)
        ->assertJsonPath('field_activity_achieved', 1)
        ->assertJsonPath('field_activity_remaining', 0)
        ->assertJsonPath('field_activity_percentage', 0);
});
