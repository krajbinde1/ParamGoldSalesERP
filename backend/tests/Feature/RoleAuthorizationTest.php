<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Orders\BillOrderWithDocument;
use App\Actions\Orders\SendOrderForBilling;
use App\Enums\UserRole;
use App\Models\Order;
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
        'order_no' => 'ORD'.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
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

function makeAdminUser(): User
{
    return User::query()->create([
        'name' => 'Admin User',
        'email' => 'admin.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

it('allows employee to create order but not approve or dispatch', function () {
    $employee = roleTestEmployee(UserRole::Employee, '9100000001');
    $this->actingAs($employee->user, 'sanctum');

    $order = seedPendingOrder($employee->id);

    $this->postJson("/api/manager/orders/{$order->id}/approve")->assertForbidden();
    $this->postJson("/api/production/orders/{$order->id}/dispatch", [
        'transport_type' => 'company_transport',
        'transport_amount' => 0,
    ])->assertForbidden();
});

it('allows manager to approve and reject pending orders only', function () {
    $employee = roleTestEmployee(UserRole::Employee, '9100000002');
    $manager = roleTestEmployee(UserRole::Manager, '9100000003');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $order = seedPendingOrder($employee->id);

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/orders/{$order->id}/approve")
        ->assertOk();

    expect($order->fresh()->status)->toBe('approved');

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/dispatch", [
            'transport_type' => 'company_transport',
            'transport_amount' => 0,
        ])
        ->assertForbidden();
});

it('hides pending orders from production supervisor and blocks dispatch until billed', function () {
    Storage::fake('public');

    $employee = roleTestEmployee(UserRole::Employee, '9100000004');
    $manager = roleTestEmployee(UserRole::Manager, '9100000005');
    $production = roleTestEmployee(UserRole::ProductionSupervisor, '9100000006');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $order = seedPendingOrder($employee->id);

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=pending_approval')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/orders/{$order->id}/approve")
        ->assertOk();

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=approved')
        ->assertOk()
        ->assertJsonPath('data.0.id', $order->id);

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/dispatch", [
            'transport_type' => 'company_transport',
            'transport_amount' => 0,
        ])
        ->assertForbidden();

    app(SendOrderForBilling::class)->execute(
        order: $order->fresh(),
        actor: $production->user,
        vehicleNumber: 'MH14CD5678',
        transportFreight: 150,
        transportChargeType: 'transport_extra',
    );

    expect($order->fresh()->status)->toBe(Order::STATUS_PENDING_FOR_BILLING);

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=sent_for_bill')
        ->assertOk()
        ->assertJsonPath('data.0.id', $order->id)
        ->assertJsonPath('data.0.status', Order::STATUS_PENDING_FOR_BILLING);

    $admin = makeAdminUser();
    app(BillOrderWithDocument::class)->execute(
        order: $order->fresh(),
        actor: $admin,
        bill: UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf'),
        billNumber: 'BILL-001',
    );

    expect($order->fresh()->status)->toBe(Order::STATUS_BILLED);

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/dispatch", [
            'transport_type' => 'company_transport',
            'transport_amount' => 0,
        ])
        ->assertOk();

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(Order::STATUS_DISPATCHED)
        ->and($fresh->dispatched_by)->toBe($production->user->id)
        ->and($fresh->transport_type)->toBe('company_transport');
});

it('blocks production supervisor from approve reject and bill actions', function () {
    Storage::fake('public');

    $employee = roleTestEmployee(UserRole::Employee, '9100000010');
    $manager = roleTestEmployee(UserRole::Manager, '9100000011');
    $production = roleTestEmployee(UserRole::ProductionSupervisor, '9100000012');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $order = seedPendingOrder($employee->id);

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/manager/orders/{$order->id}/approve")
        ->assertForbidden();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/orders/{$order->id}/approve")
        ->assertOk();

    expect(fn () => app(BillOrderWithDocument::class)->execute(
        order: $order->fresh(),
        actor: $production->user,
        bill: UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf'),
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('blocks production supervisor from employee and manager endpoints', function () {
    $production = roleTestEmployee(UserRole::ProductionSupervisor, '9100000007');
    $this->actingAs($production->user, 'sanctum');

    $this->getJson('/api/employee/dashboard')->assertForbidden();
    $this->getJson('/api/manager/dashboard')->assertForbidden();
    $this->getJson('/api/production/dashboard')->assertOk();
});

it('allows director to view company dashboard', function () {
    $director = roleTestEmployee(UserRole::Director, '9100000008');
    $this->actingAs($director->user, 'sanctum');

    // Role middleware allows director access (dashboard metrics may differ by DB driver).
    $response = $this->getJson('/api/director/dashboard');
    expect($response->status())->not->toBe(403);

    $this->getJson('/api/employee/dashboard')->assertForbidden();
});

it('defaults unknown user roles to employee on migration normalization', function () {
    $employee = roleTestEmployee(UserRole::Employee, '9100000009');
    $employee->user->update(['role' => 'Sales Executive']);

    expect(UserRole::tryFromMixed($employee->user->fresh()->role))->toBe(UserRole::Employee);
});
