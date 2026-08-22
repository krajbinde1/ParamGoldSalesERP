<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Orders\BillOrderWithDocument;
use App\Actions\Orders\RejectOrderWithRemarks;
use App\Actions\Orders\SendOrderForBilling;
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
        'full_name' => $role->label().' User '.$mobile,
        'mobile' => $mobile,
        'email' => str_replace('_', '.', $role->value).'.'.$mobile.'.ow@example.com',
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
        ->assertJsonPath('data.status_label', 'Pending for Manager Approval')
        ->assertJsonStructure(['data' => ['timeline', 'dealer' => ['firm_name', 'village', 'mobile']]]);
});

it('blocks manager reject without remarks and accepts with remarks', function () {
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000003');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000004');
    $employee->update(['reporting_manager_id' => $manager->id]);
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

it('scopes manager order list to direct reports only', function () {
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000011');
    $report = orderWorkflowEmployee(UserRole::Employee, '9200000012');
    $other = orderWorkflowEmployee(UserRole::Employee, '9200000013');
    $report->update(['reporting_manager_id' => $manager->id]);

    $visible = orderWorkflowPending($report->id);
    orderWorkflowPending($other->id);

    $this->actingAs($manager->user, 'sanctum')
        ->getJson('/api/manager/orders?status=pending_approval')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('counts.pending_approval', 1);
});

it('allows manager to edit a pending team order and stores edit audit', function () {
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000014');
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000015');
    $employee->update(['reporting_manager_id' => $manager->id]);

    $dealer = \App\Models\Dealer::query()->create([
        'firm_name' => 'Edit Dealer',
        'owner_name' => 'Owner',
        'mobile' => '9777777777',
        'address' => 'Edit Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'village' => 'Edit Village',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ]);

    $product = \App\Models\Product::query()->create([
        'product_code' => 'PG-EDIT-1',
        'product_name' => 'Edit Product',
        'dealer_price' => 10,
        'gst_percentage' => 18,
        'uom' => 'Nos',
        'nos_per_case' => 10,
        'status' => true,
    ]);

    $order = Order::query()->create([
        'order_no' => 'ORD'.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
        'order_date' => now('Asia/Kolkata')->toDateString(),
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'status' => Order::STATUS_PENDING_APPROVAL,
        'payment_type' => 'Credit',
        'subtotal' => 100,
        'discount_amount' => 0,
        'gst_amount' => 18,
        'grand_total' => 118,
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'case_quantity' => 1,
        'nos_per_case' => 10,
        'total_quantity_nos' => 10,
        'quantity' => 10,
        'unit' => 'Nos',
        'rate_per_no' => 10,
        'rate' => 10,
        'discount_percentage' => 0,
        'discount_amount' => 0,
        'gst_percentage' => 18,
        'base_amount' => 100,
        'taxable_amount' => 100,
        'gst_amount' => 18,
        'final_amount' => 118,
        'line_total' => 118,
    ]);

    $this->actingAs($manager->user, 'sanctum')
        ->putJson("/api/manager/orders/{$order->id}", [
            'dealer_id' => $dealer->id,
            'remarks' => 'Manager corrected qty',
            'items' => [[
                'product_id' => $product->id,
                'case_quantity' => 2,
                'rate_per_no' => 10,
                'discount_type' => 'percentage',
                'discount_value' => 0,
                'gst_percentage' => 18,
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('grand_total', 236);

    $fresh = $order->fresh();
    expect((float) $fresh->grand_total)->toBe(236.0)
        ->and($fresh->last_edited_by)->toBe($manager->user->id)
        ->and($fresh->last_edited_by_role)->toBe(Order::REJECTED_BY_ROLE_SALES_MANAGER)
        ->and($fresh->items()->count())->toBe(1)
        ->and((int) $fresh->items()->first()->case_quantity)->toBe(2);
});

it('allows manager to approve team order', function () {
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000016');
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000017');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $order = orderWorkflowPending($employee->id);

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/orders/{$order->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', Order::STATUS_APPROVED);

    $fresh = $order->fresh();
    expect($fresh->approved_by)->toBe($manager->user->id)
        ->and($fresh->approved_at)->not->toBeNull()
        ->and($fresh->displayStatusLabel())->toBe('Approved by Sales Manager');
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
    app(SendOrderForBilling::class)->execute(
        order: $order->fresh(),
        actor: $production->user,
        vehicleNumber: 'MH12XY9999',
        transportFreight: 100,
        transportChargeType: 'transport_extra',
    );

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

it('blocks admin from billing manager-approved orders before send-for-bill', function () {
    Storage::fake('public');

    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000020');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000021');
    $admin = orderWorkflowAdmin();
    $order = orderWorkflowPending($employee->id);
    $order->approve($manager->user->id);

    expect($order->fresh()->status)->toBe(Order::STATUS_APPROVED)
        ->and($order->fresh()->canBeBilled())->toBeFalse()
        ->and($order->fresh()->canTransitionTo(Order::STATUS_BILLED))->toBeFalse();

    expect(fn () => app(BillOrderWithDocument::class)->execute(
        order: $order->fresh(),
        actor: $admin,
        bill: UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf'),
        billNumber: 'BILL-TOO-EARLY',
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    expect($order->fresh()->status)->toBe(Order::STATUS_APPROVED);
});

it('exposes approved orders to production supervisor immediately after manager approval', function () {
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000022');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000023');
    $production = orderWorkflowEmployee(UserRole::ProductionSupervisor, '9200000024');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $order = orderWorkflowPending($employee->id);

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/orders/{$order->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', Order::STATUS_APPROVED)
        ->assertJsonPath('data.status_label', 'Approved by Sales Manager');

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=approved')
        ->assertOk()
        ->assertJsonPath('data.0.id', $order->id)
        ->assertJsonPath('data.0.status', Order::STATUS_APPROVED)
        ->assertJsonPath('data.0.status_label', 'Approved by Sales Manager')
        ->assertJsonPath('data.0.approved_by_name', $manager->user->name);

    $detail = $this->actingAs($production->user, 'sanctum')
        ->getJson("/api/production/orders/{$order->id}")
        ->assertOk()
        ->json('data');

    expect($detail['can_send_for_bill'])->toBeTrue()
        ->and($detail['can_bill'])->toBeFalse()
        ->and($detail['awaiting_send_for_bill'])->toBeTrue()
        ->and($detail['approved_by_name'])->toBe($manager->user->name);
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

it('allows production to send approved order for billing then admin bills', function () {
    Storage::fake('public');

    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000014');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000015');
    $production = orderWorkflowEmployee(UserRole::ProductionSupervisor, '9200000016');
    $admin = orderWorkflowAdmin();
    $order = orderWorkflowPending($employee->id);

    $order->approve($manager->user->id);

    $vehicle = \App\Models\Vehicle::query()->create([
        'vehicle_number' => 'MH12AB1234',
        'vehicle_name' => 'Tata Ace',
        'vehicle_type' => 'Pickup',
        'is_active' => true,
        'created_by' => $production->user->id,
    ]);

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/send-for-bill", [
            'vehicle_id' => $vehicle->id,
            'transport_charge_type' => 'transport_extra',
            'transport_freight' => 250,
            'transport_remark' => 'Ready for Tally billing',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', Order::STATUS_PENDING_FOR_BILLING)
        ->assertJsonPath('data.status_label', 'Pending for Billing')
        ->assertJsonPath('data.vehicle_number', 'MH12AB1234')
        ->assertJsonPath('data.vehicle_id', $vehicle->id)
        ->assertJsonPath('data.transport_amount', 250)
        ->assertJsonPath('data.transport_charge_type', 'transport_extra')
        ->assertJsonPath('data.original_grand_total', 100)
        ->assertJsonPath('data.transport_adjustment', 250)
        ->assertJsonPath('data.final_grand_total', 350)
        ->assertJsonPath('data.grand_total', 350);

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(Order::STATUS_PENDING_FOR_BILLING)
        ->and($fresh->sent_for_bill_by)->toBe($production->user->id)
        ->and($fresh->vehicle_id)->toBe($vehicle->id)
        ->and((float) $fresh->transport_amount)->toBe(250.0)
        ->and($fresh->transport_charge_type)->toBe('transport_extra')
        ->and((float) $fresh->original_grand_total)->toBe(100.0)
        ->and((float) $fresh->transport_adjustment)->toBe(250.0)
        ->and((float) $fresh->grand_total)->toBe(350.0)
        ->and($fresh->transport_remark)->toBe('Ready for Tally billing');

    $timeline = collect($fresh->workflowTimeline());
    expect($timeline->pluck('key')->all())->toContain('pending_for_billing');
    expect($timeline->firstWhere('key', 'pending_for_billing')['label'])
        ->toBe('Sent for Bill by Production Supervisor');
    expect($timeline->firstWhere('key', 'billed')['label'])->toBe('Billed by Admin');
    expect($timeline->firstWhere('key', 'billed')['completed'])->toBeFalse();

    app(BillOrderWithDocument::class)->execute(
        order: $fresh,
        actor: $admin,
        bill: UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf'),
        billNumber: 'BILL-SFB-1',
        billDate: now('Asia/Kolkata')->toDateString(),
    );

    expect($order->fresh()->status)->toBe(Order::STATUS_BILLED)
        ->and($order->fresh()->bill_number)->toBe('BILL-SFB-1');
});

it('allows production to create vehicle and list active vehicles', function () {
    $production = orderWorkflowEmployee(UserRole::ProductionSupervisor, '9200000091');

    $this->actingAs($production->user, 'sanctum')
        ->postJson('/api/production/vehicles', [
            'vehicle_number' => 'mh 20 ab 1234',
            'vehicle_name' => 'Tata Ace',
            'vehicle_type' => 'Pickup',
        ])
        ->assertCreated()
        ->assertJsonPath('data.vehicle_number', 'MH20AB1234')
        ->assertJsonPath('data.display_label', 'MH20AB1234 - Tata Ace');

    $this->actingAs($production->user, 'sanctum')
        ->postJson('/api/production/vehicles', [
            'vehicle_number' => 'MH20AB1234',
        ])
        ->assertUnprocessable();

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/vehicles')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('formats short order number for production lists without changing stored order_no', function () {
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000092');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000093');
    $production = orderWorkflowEmployee(UserRole::ProductionSupervisor, '9200000094');
    $order = orderWorkflowPending($employee->id);
    $order->update(['order_no' => 'PG-20260813-0001']);
    $order->approve($manager->user->id);

    expect($order->fresh()->shortOrderNo())->toBe('PG-0001')
        ->and($order->fresh()->order_no)->toBe('PG-20260813-0001');

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=approved')
        ->assertOk()
        ->assertJsonPath('data.0.order_no', 'PG-20260813-0001')
        ->assertJsonPath('data.0.short_order_no', 'PG-0001');
});

it('blocks non-production roles from send-for-bill and requires vehicle + freight', function () {
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000017');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000018');
    $production = orderWorkflowEmployee(UserRole::ProductionSupervisor, '9200000019');
    $admin = orderWorkflowAdmin();
    $order = orderWorkflowPending($employee->id);
    $order->approve($manager->user->id);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/send-for-bill", [
            'vehicle_number' => 'MH12AB1234',
            'transport_freight' => 100,
        ])
        ->assertForbidden();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/send-for-bill", [
            'vehicle_number' => 'MH12AB1234',
            'transport_freight' => 100,
        ])
        ->assertForbidden();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/send-for-bill", [
            'vehicle_number' => 'MH12AB1234',
            'transport_freight' => 100,
        ])
        ->assertForbidden();

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/send-for-bill", [
            'transport_freight' => 100,
        ])
        ->assertUnprocessable();

    expect(fn () => app(SendOrderForBilling::class)->execute(
        order: $order->fresh(),
        actor: $admin,
        vehicleNumber: 'MH12AB9999',
        transportFreight: 50,
        transportChargeType: 'company_transport',
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('allows production supervisor to mark billed orders as dispatched with optional remark', function () {
    Storage::fake('public');

    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000101');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000102');
    $production = orderWorkflowEmployee(UserRole::ProductionSupervisor, '9200000103');
    $admin = orderWorkflowAdmin();
    $order = orderWorkflowPending($employee->id);
    $order->approve($manager->user->id);

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/dispatch", [
            'remark' => 'Should not dispatch approved orders',
        ])
        ->assertForbidden();

    app(SendOrderForBilling::class)->execute(
        order: $order->fresh(),
        actor: $production->user,
        vehicleNumber: 'MH14DS1111',
        transportFreight: 120,
        transportChargeType: 'transport_extra',
    );

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/dispatch", [
            'remark' => 'Should not dispatch pending-for-billing orders',
        ])
        ->assertForbidden();

    app(BillOrderWithDocument::class)->execute(
        order: $order->fresh(),
        actor: $admin,
        bill: UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf'),
        billNumber: 'BILL-DSP-1',
    );

    $this->actingAs($employee->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/dispatch")
        ->assertForbidden();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/dispatch")
        ->assertForbidden();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/dispatch")
        ->assertForbidden();

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=billed')
        ->assertOk()
        ->assertJsonPath('data.0.id', $order->id)
        ->assertJsonPath('data.0.can_dispatch', true)
        ->assertJsonPath('meta.counts.billed', 1)
        ->assertJsonPath('meta.counts.dispatched', 0);

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/dispatch", [
            'remark' => 'Loaded on MH14DS1111',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', Order::STATUS_DISPATCHED)
        ->assertJsonPath('data.dispatched_by', $production->user->id)
        ->assertJsonPath('data.dispatched_by_name', $production->user->name)
        ->assertJsonPath('data.dispatch_remark', 'Loaded on MH14DS1111')
        ->assertJsonPath('data.can_dispatch', false)
        ->assertJsonPath('data.can_edit', false)
        ->assertJsonPath('meta.counts.billed', 0)
        ->assertJsonPath('meta.counts.dispatched', 1);

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(Order::STATUS_DISPATCHED)
        ->and($fresh->dispatched_by)->toBe($production->user->id)
        ->and($fresh->dispatch_remark)->toBe('Loaded on MH14DS1111')
        ->and($fresh->dispatched_at)->not->toBeNull()
        ->and($fresh->dispatch_date?->toDateString())->toBe(now('Asia/Kolkata')->toDateString());

    $timeline = collect($fresh->workflowTimeline());
    expect($timeline->firstWhere('key', 'dispatched')['label'])
        ->toBe('Dispatched by Production Supervisor');
    expect($timeline->firstWhere('key', 'dispatched')['actor'])->toBe($production->user->name);
    expect($timeline->firstWhere('key', 'dispatched')['completed'])->toBeTrue();

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=billed')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.counts.billed', 0)
        ->assertJsonPath('meta.counts.dispatched', 1);

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=dispatched')
        ->assertOk()
        ->assertJsonPath('data.0.id', $order->id)
        ->assertJsonPath('data.0.dispatched_by_name', $production->user->name);

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/dispatch", [
            'remark' => 'Already dispatched',
        ])
        ->assertForbidden();
});

it('lets production supervisor upload a received copy only after dispatch', function () {
    Storage::fake('public');

    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000201');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000202');
    $production = orderWorkflowEmployee(UserRole::ProductionSupervisor, '9200000203');
    $admin = orderWorkflowAdmin();
    $order = orderWorkflowPending($employee->id);
    $order->approve($manager->user->id);

    $file = UploadedFile::fake()->image('received-copy.jpg');

    $this->actingAs($production->user, 'sanctum')
        ->post("/api/production/orders/{$order->id}/received-copy", [
            'received_copy' => $file,
        ], ['Accept' => 'application/json'])
        ->assertForbidden();

    app(SendOrderForBilling::class)->execute(
        order: $order->fresh(),
        actor: $production->user,
        vehicleNumber: 'MH14RC1111',
        transportFreight: 80,
        transportChargeType: 'transport_extra',
    );

    app(BillOrderWithDocument::class)->execute(
        order: $order->fresh(),
        actor: $admin,
        bill: UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf'),
        billNumber: 'BILL-RC-1',
    );

    $this->actingAs($production->user, 'sanctum')
        ->post("/api/production/orders/{$order->id}/received-copy", [
            'received_copy' => $file,
        ], ['Accept' => 'application/json'])
        ->assertForbidden();

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/dispatch", [
            'remark' => 'Dispatched for delivery',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', Order::STATUS_DISPATCHED)
        ->assertJsonPath('data.received_copy_url', null)
        ->assertJsonPath('data.can_upload_received_copy', true);

    $this->actingAs($employee->user, 'sanctum')
        ->post("/api/production/orders/{$order->id}/received-copy", [
            'received_copy' => UploadedFile::fake()->image('other.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertForbidden();

    $this->actingAs($admin, 'sanctum')
        ->post("/api/production/orders/{$order->id}/received-copy", [
            'received_copy' => UploadedFile::fake()->image('admin.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertForbidden();

    $this->actingAs($production->user, 'sanctum')
        ->post("/api/production/orders/{$order->id}/received-copy", [
            'received_copy' => UploadedFile::fake()->image('pod.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.status', Order::STATUS_DISPATCHED)
        ->assertJsonPath('data.can_upload_received_copy', true)
        ->assertJsonPath('data.received_copy_uploaded_by_name', $production->user->name);

    $fresh = $order->fresh();
    expect($fresh->received_copy_path)->not->toBeNull()
        ->and($fresh->received_copy_uploaded_by)->toBe($production->user->id)
        ->and($fresh->received_copy_uploaded_at)->not->toBeNull()
        ->and($fresh->status)->toBe(Order::STATUS_DISPATCHED)
        ->and($fresh->dispatch_remark)->toBe('Dispatched for delivery');

    Storage::disk('public')->assertExists($fresh->received_copy_path);

    $this->actingAs($production->user, 'sanctum')
        ->getJson("/api/production/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.received_copy_path', $fresh->received_copy_path)
        ->assertJsonFragment(['received_copy_url' => $fresh->receivedCopyUrl()]);
});

it('lets production supervisor list rejected orders without dispatching them', function () {
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000111');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000112');
    $production = orderWorkflowEmployee(UserRole::ProductionSupervisor, '9200000113');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $order = orderWorkflowPending($employee->id);

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/orders/{$order->id}/reject", [
            'rejection_reason' => 'Dealer credit hold',
        ])
        ->assertOk();

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=rejected')
        ->assertOk()
        ->assertJsonPath('data.0.id', $order->id)
        ->assertJsonPath('data.0.status', Order::STATUS_REJECTED)
        ->assertJsonPath('data.0.can_dispatch', false)
        ->assertJsonPath('meta.counts.rejected', 1);

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/dispatch")
        ->assertForbidden();
});

it('deducts company transport from original grand total only once', function () {
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000121');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000122');
    $production = orderWorkflowEmployee(UserRole::ProductionSupervisor, '9200000123');
    $order = orderWorkflowPending($employee->id);
    $order->approve($manager->user->id);

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/send-for-bill", [
            'vehicle_number' => 'MH12CT0001',
            'transport_charge_type' => 'company_transport',
            'transport_freight' => 5000,
        ])
        ->assertUnprocessable();

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/send-for-bill", [
            'vehicle_number' => 'MH12CT0001',
            'transport_charge_type' => 'company_transport',
            'transport_freight' => 15,
        ])
        ->assertOk()
        ->assertJsonPath('data.original_grand_total', 100)
        ->assertJsonPath('data.transport_adjustment', -15)
        ->assertJsonPath('data.final_grand_total', 85)
        ->assertJsonPath('data.grand_total', 85);

    $fresh = $order->fresh();
    expect((float) $fresh->grand_total)->toBe(85.0)
        ->and((float) $fresh->original_grand_total)->toBe(100.0)
        ->and((float) $fresh->subtotal)->toBe(100.0);

    $fresh->recalculateTotals();
    expect((float) $fresh->fresh()->grand_total)->toBe(85.0)
        ->and((float) $fresh->fresh()->original_grand_total)->toBe(100.0);
});

it('lets production hold an approved order and release it without manager re-approval', function () {
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000201');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000202');
    $production = orderWorkflowEmployee(UserRole::ProductionSupervisor, '9200000203');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $order = orderWorkflowPending($employee->id);
    $order->approve($manager->user->id);
    $originalApprovedAt = $order->fresh()->approved_at?->toDateTimeString();
    $originalOrderNo = $order->order_no;

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/hold", [])
        ->assertUnprocessable();

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/hold", [
            'remark' => 'Stock not available',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', Order::STATUS_ON_HOLD)
        ->assertJsonPath('data.status_label', 'On Hold')
        ->assertJsonPath('data.can_send_for_bill', false)
        ->assertJsonPath('data.can_hold', false)
        ->assertJsonPath('data.can_release_hold', true)
        ->assertJsonPath('data.hold_remark', 'Stock not available')
        ->assertJsonPath('data.approved_by', $manager->user->id);

    $held = $order->fresh();
    expect($held->status)->toBe(Order::STATUS_ON_HOLD)
        ->and($held->held_by)->toBe($production->user->id)
        ->and($held->hold_remark)->toBe('Stock not available')
        ->and($held->approved_by)->toBe($manager->user->id)
        ->and($held->approved_at?->toDateTimeString())->toBe($originalApprovedAt)
        ->and($held->order_no)->toBe($originalOrderNo)
        ->and($held->canBeSentForBilling())->toBeFalse();

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/send-for-bill", [
            'vehicle_number' => 'MH12HOLD01',
            'transport_charge_type' => 'transport_extra',
            'transport_freight' => 10,
        ])
        ->assertForbidden();

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=approved')
        ->assertOk()
        ->assertJsonMissing(['id' => $order->id]);

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=on_hold')
        ->assertOk()
        ->assertJsonPath('data.0.id', $order->id)
        ->assertJsonPath('meta.counts.on_hold', 1);

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/release-hold")
        ->assertOk()
        ->assertJsonPath('data.status', Order::STATUS_APPROVED)
        ->assertJsonPath('data.can_send_for_bill', true)
        ->assertJsonPath('data.can_hold', true);

    $released = $order->fresh();
    expect($released->status)->toBe(Order::STATUS_APPROVED)
        ->and($released->hold_released_by)->toBe($production->user->id)
        ->and($released->approved_by)->toBe($manager->user->id)
        ->and($released->order_no)->toBe($originalOrderNo);

    $timelineKeys = collect($released->workflowTimeline())->pluck('key')->all();
    expect($timelineKeys)->toContain('held')
        ->and($timelineKeys)->toContain('released')
        ->and($timelineKeys)->toContain('approved');
});

it('lets production revert an approved order for manager edit and re-approval on the same order number', function () {
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000211');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000212');
    $production = orderWorkflowEmployee(UserRole::ProductionSupervisor, '9200000213');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $order = orderWorkflowPending($employee->id);
    $order->approve($manager->user->id);
    $originalApprovedAt = $order->fresh()->approved_at?->toDateTimeString();
    $originalOrderNo = $order->order_no;

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/revert-to-manager", [])
        ->assertUnprocessable();

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/revert-to-manager", [
            'remark' => 'Please correct product quantity before production.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', Order::STATUS_REVERTED_TO_MANAGER)
        ->assertJsonPath('data.status_label', 'Returned by Production')
        ->assertJsonPath('data.can_send_for_bill', false)
        ->assertJsonPath('data.revert_remark', 'Please correct product quantity before production.');

    expect($order->fresh()->status)->toBe(Order::STATUS_REVERTED_TO_MANAGER)
        ->and($order->fresh()->reverted_by)->toBe($production->user->id)
        ->and($order->fresh()->approved_by)->toBe($manager->user->id)
        ->and($order->fresh()->approved_at?->toDateTimeString())->toBe($originalApprovedAt)
        ->and($order->fresh()->order_no)->toBe($originalOrderNo);

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=approved')
        ->assertOk();

    $approvedList = $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=approved')
        ->json('data');
    expect(collect($approvedList)->pluck('id'))->not->toContain($order->id);

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=reverted_to_manager')
        ->assertOk()
        ->assertJsonPath('data.0.id', $order->id);

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/send-for-bill", [
            'vehicle_number' => 'MH12REV001',
            'transport_charge_type' => 'transport_extra',
            'transport_freight' => 10,
        ])
        ->assertForbidden();

    $this->actingAs($manager->user, 'sanctum')
        ->getJson('/api/manager/orders?status=reverted_to_manager')
        ->assertOk()
        ->assertJsonPath('data.0.id', $order->id)
        ->assertJsonPath('counts.returned_by_production', 1);

    $detail = $this->actingAs($manager->user, 'sanctum')
        ->getJson("/api/manager/orders/{$order->id}")
        ->assertOk()
        ->json('data');

    expect($detail['can_approve'])->toBeTrue()
        ->and($detail['can_edit'])->toBeTrue()
        ->and($detail['can_reject'])->toBeTrue()
        ->and($detail['revert_remark'])->toBe('Please correct product quantity before production.')
        ->and($detail['reverted_by_name'])->toBe($production->user->name)
        ->and($detail['status'])->toBe(Order::STATUS_REVERTED_TO_MANAGER);

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/orders/{$order->id}/approve", [
            'remark' => 'Quantity corrected',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', Order::STATUS_APPROVED)
        ->assertJsonPath('message', 'Order re-approved successfully.');

    $reapproved = $order->fresh();
    expect($reapproved->status)->toBe(Order::STATUS_APPROVED)
        ->and($reapproved->order_no)->toBe($originalOrderNo)
        ->and($reapproved->id)->toBe($order->id)
        ->and($reapproved->approved_by)->toBe($manager->user->id)
        ->and($reapproved->approved_at?->toDateTimeString())->toBe($originalApprovedAt)
        ->and($reapproved->reapproved_by)->toBe($manager->user->id)
        ->and($reapproved->canBeSentForBilling())->toBeTrue();

    $timelineKeys = collect($reapproved->workflowTimeline())->pluck('key')->all();
    expect($timelineKeys)->toContain('approved')
        ->and($timelineKeys)->toContain('reverted')
        ->and($timelineKeys)->toContain('reapproved');

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=approved')
        ->assertOk()
        ->assertJsonPath('data.0.id', $order->id)
        ->assertJsonPath('data.0.can_send_for_bill', true);
});

it('blocks hold and revert after the order is billed or dispatched', function () {
    $employee = orderWorkflowEmployee(UserRole::Employee, '9200000221');
    $manager = orderWorkflowEmployee(UserRole::Manager, '9200000222');
    $production = orderWorkflowEmployee(UserRole::ProductionSupervisor, '9200000223');
    $order = orderWorkflowPending($employee->id);
    $order->approve($manager->user->id);
    $order->forceFill(['status' => Order::STATUS_BILLED])->saveQuietly();

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/hold", [
            'remark' => 'Too late to hold',
        ])
        ->assertForbidden();

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/orders/{$order->id}/revert-to-manager", [
            'remark' => 'Too late to revert',
        ])
        ->assertForbidden();
});
