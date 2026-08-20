<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
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

function dashboardTargetEmployee(string $name, string $mobile): \App\Models\Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $name,
        'mobile' => $mobile,
        'email' => strtolower(str_replace(' ', '.', $name)).'.'.$mobile.'@example.com',
        'department' => 'Sales',
        'designation' => 'Sales Officer',
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
        'role' => UserRole::Employee->value,
    ])->employee;
}

function dashboardTargetDealer(): Dealer
{
    return Dealer::query()->create([
        'firm_name' => 'Target Dealer',
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
}

it('returns each sales employee their own current-month targets using employee_id not user id', function () {
    \App\Models\User::query()->create([
        'name' => 'Padding User',
        'email' => 'padding.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
    ]);

    $first = dashboardTargetEmployee('First Sales Employee', '9300000001');
    $second = dashboardTargetEmployee('Second Sales Employee', '9300000002');
    $third = dashboardTargetEmployee('Third Sales Employee', '9300000003');
    $dealer = dashboardTargetDealer();

    expect($first->user->id)->not->toBe($first->id)
        ->and($second->user->id)->not->toBe($second->id);

    WeeklyTarget::query()->create([
        'employee_id' => $first->id,
        'week_start_date' => '2026-08-11',
        'week_end_date' => '2026-08-16',
        'sales_target' => 500000,
        'collection_target' => 100000,
        'field_activity_target' => 50,
        'status' => 'active',
    ]);
    WeeklyTarget::query()->create([
        'employee_id' => $second->id,
        'week_start_date' => '2026-08-11',
        'week_end_date' => '2026-08-16',
        'sales_target' => 250000,
        'collection_target' => 75000,
        'field_activity_target' => 20,
        'status' => 'active',
    ]);

    Order::query()->create([
        'order_no' => 'ORD-TGT-1001',
        'order_date' => '2026-08-14',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $first->id,
        'status' => Order::STATUS_DISPATCHED,
        'payment_type' => 'Credit',
        'subtotal' => 12000,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 12000,
        'updated_at' => '2026-08-14 10:00:00',
    ]);
    Order::query()->create([
        'order_no' => 'ORD-TGT-1002',
        'order_date' => '2026-08-14',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $second->id,
        'status' => Order::STATUS_DISPATCHED,
        'payment_type' => 'Credit',
        'subtotal' => 8000,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 8000,
        'updated_at' => '2026-08-14 10:00:00',
    ]);

    Collection::query()->create([
        'collection_date' => '2026-08-15',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $first->id,
        'amount' => 4000,
        'status' => Collection::STATUS_RECEIVED,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-TGT-1',
    ]);
    Collection::query()->create([
        'collection_date' => '2026-08-15',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $second->id,
        'amount' => 1500,
        'status' => Collection::STATUS_RECEIVED,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-TGT-2',
    ]);

    FieldActivity::query()->create([
        'employee_id' => $first->id,
        'farmer_name' => 'Ramesh Patil',
        'village' => 'Waluj',
        'taluka' => 'Gangapur',
        'activity_date' => '2026-08-14',
        'activity_time' => '10:00:00',
        'photo_path' => 'field-activities/first.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);
    FieldActivity::query()->create([
        'employee_id' => $first->id,
        'farmer_name' => 'Suresh Patil',
        'village' => 'Waluj',
        'taluka' => 'Gangapur',
        'activity_date' => '2026-08-15',
        'activity_time' => '11:00:00',
        'photo_path' => 'field-activities/first-2.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);
    FieldActivity::query()->create([
        'employee_id' => $second->id,
        'farmer_name' => 'Hidden Farmer',
        'village' => 'Pune',
        'taluka' => 'Haveli',
        'activity_date' => '2026-08-14',
        'activity_time' => '12:00:00',
        'photo_path' => 'field-activities/second.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);

    $this->actingAs($first->user, 'sanctum')
        ->getJson('/api/employee/dashboard')
        ->assertOk()
        ->assertJsonPath('employee.id', $first->id)
        ->assertJsonPath('sales_target', 500000)
        ->assertJsonPath('sales_achieved', 12000)
        ->assertJsonPath('collection_target', 100000)
        ->assertJsonPath('collection_achieved', 4000)
        ->assertJsonPath('weekly_sales_target', 500000)
        ->assertJsonPath('weekly_collection_target', 100000)
        ->assertJsonPath('field_activity_target', 50)
        ->assertJsonPath('field_activity_achieved', 2)
        ->assertJsonPath('field_activity_remaining', 48)
        ->assertJsonPath('field_activity_percentage', 4);

    $this->actingAs($second->user, 'sanctum')
        ->getJson('/api/employee/dashboard')
        ->assertOk()
        ->assertJsonPath('employee.id', $second->id)
        ->assertJsonPath('sales_target', 250000)
        ->assertJsonPath('sales_achieved', 8000)
        ->assertJsonPath('collection_target', 75000)
        ->assertJsonPath('collection_achieved', 1500)
        ->assertJsonPath('field_activity_target', 20)
        ->assertJsonPath('field_activity_achieved', 1)
        ->assertJsonPath('field_activity_remaining', 19)
        ->assertJsonPath('field_activity_percentage', 5);

    $this->actingAs($third->user, 'sanctum')
        ->getJson('/api/employee/dashboard')
        ->assertOk()
        ->assertJsonPath('employee.id', $third->id)
        ->assertJsonPath('sales_target', 0)
        ->assertJsonPath('sales_achieved', 0)
        ->assertJsonPath('collection_target', 0)
        ->assertJsonPath('collection_achieved', 0)
        ->assertJsonPath('field_activity_target', 0)
        ->assertJsonPath('field_activity_achieved', 0)
        ->assertJsonPath('field_activity_remaining', 0)
        ->assertJsonPath('field_activity_percentage', 0);
});
