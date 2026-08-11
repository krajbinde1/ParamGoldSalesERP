<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Orders\BillOrderWithDocument;
use App\Actions\Orders\RejectOrderWithRemarks;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function orderWorkflowEmployee(UserRole $role, string $mobile): \App\Models\Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $role->label().' User',
        'mobile' => $mobile,
        'email' => str_replace('_', '.', $role->value).'.ow@example.com',
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

function orderWorkflowPending(int $employeeId): Order
{
    $dealerId = \App\Models\Dealer::query()->create([
        'firm_name' => 'Workflow Dealer',
        'owner_name' => 'Owner',
        'mobile' => '9888888888',
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
        'status' => Order::STATUS_PENDING_APPROVAL,
        'payment_type' => 'Credit',
        'subtotal' => 100,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 100,
    ]);
}

function orderWorkflowAdmin(): User
{
    return User::query()->create([
        'name' => 'Admin User',
        'email' => 'admin.ow.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

it('lists newest orders first by created_at', function () {
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000001');
    $older = orderWorkflowPending($employee->id);
    $newer = orderWorkflowPending($employee->id);

    $older->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();
    $newer->forceFill(['created_at' => now()->subHour()])->saveQuietly();

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/employee/orders?filter=all')
        ->assertOk()
        ->assertJsonPath('orders.0.id', $newer->id)
        ->assertJsonPath('orders.1.id', $older->id);
});

it('shows user-facing pending status label', function () {
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000002');
    $order = orderWorkflowPending($employee->id);

    $this->actingAs($employee->user, 'sanctum')
        ->getJson("/api/employee/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.status_label', 'Pending Sales Manager Approval')
        ->assertJsonStructure(['data' => ['timeline', 'dealer' => ['firm_name', 'village', 'mobile']]]);
});

it('blocks manager reject without remarks and accepts with remarks', function () {
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000003');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000004');
    $order = orderWorkflowPending($employee->id);

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/orders/{$order->id}/reject", [])
        ->assertUnprocessable();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/orders/{$order->id}/reject", ['remark' => 'Incorrect quantity'])
        ->assertOk();

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(Order::STATUS_REJECTED)
        ->and($fresh->rejected_by_role)->toBe(Order::REJECTED_BY_ROLE_SALES_MANAGER)
        ->and($fresh->rejection_remark)->toBe('Incorrect quantity')
        ->and($fresh->displayStatusLabel())->toBe('Rejected by Sales Manager');
});

it('allows admin to reject pending and approved orders with remarks', function () {
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000005');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000006');
    $admin = orderWorkflowAdmin();

    $pending = orderWorkflowPending($employee->id);
    expect(fn () => app(RejectOrderWithRemarks::class)->execute(
        order: $pending,
        actor: $admin,
        remark: '',
        rejectedByRole: Order::REJECTED_BY_ROLE_ADMIN,
    ))->toThrow(ValidationException::class);

    app(RejectOrderWithRemarks::class)->execute(
        order: $pending,
        actor: $admin,
        remark: 'Dealer credit issue',
        rejectedByRole: Order::REJECTED_BY_ROLE_ADMIN,
    );

    expect($pending->fresh()->displayStatusLabel())->toBe('Rejected by Admin');

    $approved = orderWorkflowPending($employee->id);
    $approved->approve($manager->user->id);

    app(RejectOrderWithRemarks::class)->execute(
        order: $approved->fresh(),
        actor: $admin,
        remark: 'Credit limit exceeded',
        rejectedByRole: Order::REJECTED_BY_ROLE_ADMIN,
    );

    $fresh = $approved->fresh();
    expect($fresh->status)->toBe(Order::STATUS_REJECTED)
        ->and($fresh->approved_by)->toBe($manager->user->id)
        ->and($fresh->rejected_by_role)->toBe(Order::REJECTED_BY_ROLE_ADMIN)
        ->and($fresh->displayStatusLabel())->toBe('Rejected by Admin');
});

it('blocks admin reject after billed or dispatched', function () {
    Storage::fake('public');

    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000007');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000008');
    $production = orderWorkflowEmployee(UserRole::ProductionSupervisor, '9200000009');
    $admin = orderWorkflowAdmin();
    $order = orderWorkflowPending($employee->id);

    $order->approve($manager->user->id);
    app(BillOrderWithDocument::class)->execute(
        order: $order->fresh(),
        actor: $admin,
        bill: UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf'),
        billNumber: 'BILL-OW-1',
    );

    expect(fn () => app(RejectOrderWithRemarks::class)->execute(
        order: $order->fresh(),
        actor: $admin,
        remark: 'Too late',
        rejectedByRole: Order::REJECTED_BY_ROLE_ADMIN,
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    $order->fresh()->dispatch($production->user->id, 'Out for delivery');

    expect(fn () => app(RejectOrderWithRemarks::class)->execute(
        order: $order->fresh(),
        actor: $admin,
        remark: 'Too late again',
        rejectedByRole: Order::REJECTED_BY_ROLE_ADMIN,
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('blocks admin from approving and manager from billing', function () {
    Storage::fake('public');

    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000010');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000011');
    $admin = orderWorkflowAdmin();
    $order = orderWorkflowPending($employee->id);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/manager/orders/{$order->id}/approve")
        ->assertForbidden();

    $order->approve($manager->user->id);

    expect(fn () => app(BillOrderWithDocument::class)->execute(
        order: $order->fresh(),
        actor: $manager->user,
        bill: UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf'),
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('keeps rejected orders visible in employee history', function () {
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000012');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000013');
    $order = orderWorkflowPending($employee->id);

    $order->reject($manager->user->id, 'Wrong product mix', Order::REJECTED_BY_ROLE_SALES_MANAGER);

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/employee/orders?filter=rejected')
        ->assertOk()
        ->assertJsonPath('orders.0.id', $order->id)
        ->assertJsonPath('orders.0.status_label', 'Rejected by Sales Manager');

    expect(Order::query()->whereKey($order->id)->exists())->toBeTrue();
});
