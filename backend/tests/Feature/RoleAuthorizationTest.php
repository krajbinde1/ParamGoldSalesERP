<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\EmployeeRoutePoint;
use App\Models\Order;
use App\Models\TaDaClaim;
use App\Models\TaDaSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function roleTestEmployee(UserRole $role, string $mobile): \App\Models\Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $role->label().' User',
        'mobile' => $mobile,
        'email' => str_replace('_', '.', $role->value).'@example.com',
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

function seedPendingOrder(int $employeeId): Order
{
    $dealerId = \App\Models\Dealer::query()->create([
        'firm_name' => 'Test Dealer',
        'owner_name' => 'Owner',
        'mobile' => '9999999999',
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'village' => 'Test Village',
        'status' => true,
    ])->id;

    return Order::query()->create([
        'order_no' => 'ORD999999',
        'order_date' => now('Asia/Kolkata')->toDateString(),
        'dealer_id' => $dealerId,
        'sales_employee_id' => $employeeId,
        'status' => 'pending_approval',
        'payment_type' => 'Credit',
        'subtotal' => 100,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 100,
    ]);
}

it('allows employee to create order but not approve or dispatch', function () {
    $employee = roleTestEmployee(UserRole::Employee, '9100000001');
    $this->actingAs($employee->user, 'sanctum');

    $order = seedPendingOrder($employee->id);

    $this->postJson("/manager/orders/{$order->id}/approve")->assertForbidden();
    $this->postJson("/production/orders/{$order->id}/dispatch", [
        'transport_type' => 'company_transport',
        'transport_amount' => 0,
    ])->assertForbidden();
});

it('allows manager to approve and reject pending orders only', function () {
    $employee = roleTestEmployee(UserRole::Employee, '9100000002');
    $manager = roleTestEmployee(UserRole::Manager, '9100000003');
    $order = seedPendingOrder($employee->id);

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/manager/orders/{$order->id}/approve")
        ->assertOk();

    expect($order->fresh()->status)->toBe('approved');

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/production/orders/{$order->id}/dispatch", [
            'transport_type' => 'company_transport',
            'transport_amount' => 0,
        ])
        ->assertForbidden();
});

it('allows production supervisor to dispatch approved orders only', function () {
    $employee = roleTestEmployee(UserRole::Employee, '9100000004');
    $manager = roleTestEmployee(UserRole::Manager, '9100000005');
    $production = roleTestEmployee(UserRole::ProductionSupervisor, '9100000006');
    $order = seedPendingOrder($employee->id);

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/manager/orders/{$order->id}/approve")
        ->assertOk();

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/production/orders/{$order->id}/dispatch", [
            'transport_type' => 'company_transport',
            'transport_amount' => 0,
        ])
        ->assertOk();

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(Order::STATUS_DISPATCHED);
    expect($fresh->transport_type)->toBe('company_transport');
    expect((float) $fresh->transport_amount)->toBe(0.0);
});

it('blocks production supervisor from employee and manager endpoints', function () {
    $production = roleTestEmployee(UserRole::ProductionSupervisor, '9100000007');
    $this->actingAs($production->user, 'sanctum');

    $this->getJson('/employee/dashboard')->assertForbidden();
    $this->getJson('/manager/dashboard')->assertForbidden();
    $this->getJson('/production/dashboard')->assertOk();
});

it('allows director to view company dashboard', function () {
    $director = roleTestEmployee(UserRole::Director, '9100000008');
    $this->actingAs($director->user, 'sanctum');

    $this->getJson('/director/dashboard')->assertOk();
    $this->getJson('/employee/dashboard')->assertForbidden();
});

it('defaults unknown user roles to employee on migration normalization', function () {
    $employee = roleTestEmployee(UserRole::Employee, '9100000009');
    $employee->user->update(['role' => 'Sales Executive']);

    expect(UserRole::tryFromMixed($employee->user->fresh()->role))->toBe(UserRole::Employee);
});
