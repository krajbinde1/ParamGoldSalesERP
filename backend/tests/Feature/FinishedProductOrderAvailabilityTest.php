<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Dealer;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Product;
use App\Services\Orders\FinishedProductOrderAvailabilityService;

function fgStockAvailEmployee(UserRole $role, string $mobile): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $role->label().' FG '.$mobile,
        'mobile' => $mobile,
        'email' => str_replace('_', '.', $role->value).'.'.$mobile.'.fgstock@example.com',
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

function fgStockAvailDealer(int $employeeId, string $mobile): Dealer
{
    return Dealer::query()->create([
        'firm_name' => 'FG Stock Dealer '.$mobile,
        'owner_name' => 'Owner',
        'mobile' => $mobile,
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'village' => 'Test Village',
        'status' => true,
        'assigned_employee_id' => $employeeId,
    ]);
}

function fgStockAvailProduct(string $code, string $name, float $stock): Product
{
    return Product::query()->create([
        'product_code' => $code,
        'product_name' => $name,
        'category' => 'General',
        'dealer_price' => 10,
        'gst_percentage' => 18,
        'uom' => 'Nos',
        'production_unit' => 'Nos',
        'nos_per_case' => 1,
        'status' => true,
        'current_finished_stock' => $stock,
    ]);
}

function fgStockAvailOrder(
    int $employeeId,
    int $dealerId,
    string $status,
    string $orderNo,
    string $orderDate,
): Order {
    return Order::query()->create([
        'order_no' => $orderNo,
        'order_date' => $orderDate,
        'dealer_id' => $dealerId,
        'sales_employee_id' => $employeeId,
        'status' => $status,
        'payment_type' => 'Credit',
        'subtotal' => 100,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 100,
    ]);
}

function fgStockAvailLine(Order $order, Product $product, int $qty): void
{
    $order->items()->create([
        'product_id' => $product->id,
        'case_quantity' => $qty,
        'nos_per_case' => 1,
        'total_quantity_nos' => $qty,
        'quantity' => $qty,
        'unit' => 'Nos',
        'rate_per_no' => 10,
        'rate' => 10,
        'discount_percentage' => 0,
        'discount_amount' => 0,
        'gst_percentage' => 18,
        'base_amount' => $qty * 10,
        'taxable_amount' => $qty * 10,
        'gst_amount' => 0,
        'final_amount' => $qty * 10,
        'line_total' => $qty * 10,
    ]);
}

it('allocates finished stock to older pending orders before later ones', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000101');
    $dealer = fgStockAvailDealer($employee->id, '9300001101');
    $product = fgStockAvailProduct('NEMAX-FG-1', 'NEMAX', 100);

    $first = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-1001', '2026-09-01');
    $second = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-1002', '2026-09-02');
    fgStockAvailLine($first, $product, 50);
    fgStockAvailLine($second, $product, 60);

    $allocated = app(FinishedProductOrderAvailabilityService::class)->allocate([(int) $product->id]);

    expect($allocated[$first->id][$product->id])->toMatchArray([
        'order_qty' => 50.0,
        'current_finished_stock' => 100.0,
        'allocated_to_earlier_orders' => 0.0,
        'available_for_this_order' => 50.0,
        'short_qty' => 0.0,
        'stock_status' => 'available',
        'stock_status_label' => 'Available',
        'short_label' => null,
    ])->and($allocated[$second->id][$product->id])->toMatchArray([
        'order_qty' => 60.0,
        'current_finished_stock' => 100.0,
        'allocated_to_earlier_orders' => 50.0,
        'available_for_this_order' => 50.0,
        'short_qty' => 10.0,
        'stock_status' => 'partial_stock',
        'stock_status_label' => 'Partial Stock',
        'short_label' => 'Short 10 Nos',
    ]);

    expect((float) $product->fresh()->current_finished_stock)->toBe(100.0);
});

it('calculates shortage independently for each product on the same order', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000102');
    $dealer = fgStockAvailDealer($employee->id, '9300001102');
    $nemax = fgStockAvailProduct('NEMAX-FG-2', 'NEMAX', 40);
    $gold = fgStockAvailProduct('GOLD-FG-2', 'Gold Mix', 0);

    $order = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-2001', '2026-09-03');
    fgStockAvailLine($order, $nemax, 40);
    fgStockAvailLine($order, $gold, 12);

    $rows = app(FinishedProductOrderAvailabilityService::class)->allocate()[$order->id];

    expect($rows[$nemax->id]['stock_status'])->toBe('available')
        ->and($rows[$nemax->id]['short_qty'])->toBe(0.0)
        ->and($rows[$gold->id]['stock_status'])->toBe('out_of_stock')
        ->and($rows[$gold->id]['short_qty'])->toBe(12.0)
        ->and($rows[$gold->id]['short_label'])->toBe('Short 12 Nos');
});

it('does not include the current order in allocated_to_earlier_orders', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000108');
    $dealer = fgStockAvailDealer($employee->id, '9300001108');
    $product = fgStockAvailProduct('NEMAX-FG-7', 'NEMAX', 100);

    $order = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-7001', '2026-09-01');
    fgStockAvailLine($order, $product, 80);

    $row = app(FinishedProductOrderAvailabilityService::class)->allocate([(int) $product->id])[$order->id][$product->id];

    expect($row)->toMatchArray([
        'order_qty' => 80.0,
        'current_finished_stock' => 100.0,
        'allocated_to_earlier_orders' => 0.0,
        'available_for_this_order' => 80.0,
        'short_qty' => 0.0,
        'stock_status' => 'available',
    ]);
});

it('drops dispatched, rejected, cancelled, and reverted orders from the virtual allocation queue', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000103');
    $dealer = fgStockAvailDealer($employee->id, '9300001103');
    $product = fgStockAvailProduct('NEMAX-FG-3', 'NEMAX', 100);

    $dispatched = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_DISPATCHED, 'ORD-FG-3001', '2026-09-01');
    $rejected = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_REJECTED, 'ORD-FG-3002', '2026-09-02');
    $cancelled = fgStockAvailOrder($employee->id, $dealer->id, 'cancelled', 'ORD-FG-3003', '2026-09-03');
    $reverted = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_REVERTED_TO_MANAGER, 'ORD-FG-3004', '2026-09-04');
    $delivered = fgStockAvailOrder($employee->id, $dealer->id, 'delivered', 'ORD-FG-3006', '2026-09-04');
    $pending = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-3005', '2026-09-05');
    fgStockAvailLine($dispatched, $product, 80);
    fgStockAvailLine($rejected, $product, 70);
    fgStockAvailLine($cancelled, $product, 65);
    fgStockAvailLine($reverted, $product, 55);
    fgStockAvailLine($delivered, $product, 45);
    fgStockAvailLine($pending, $product, 60);

    $allocated = app(FinishedProductOrderAvailabilityService::class)->allocate([(int) $product->id]);

    expect($allocated)->not->toHaveKey($dispatched->id)
        ->and($allocated)->not->toHaveKey($rejected->id)
        ->and($allocated)->not->toHaveKey($cancelled->id)
        ->and($allocated)->not->toHaveKey($reverted->id)
        ->and($allocated)->not->toHaveKey($delivered->id)
        ->and($allocated[$pending->id][$product->id])->toMatchArray([
            'allocated_to_earlier_orders' => 0.0,
            'available_for_this_order' => 60.0,
            'short_qty' => 0.0,
            'stock_status' => 'available',
        ]);
});

it('ignores earlier orders whose status is still open but dispatch or reject already happened', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000116');
    $dealer = fgStockAvailDealer($employee->id, '9300001116');
    $product = fgStockAvailProduct('NEMAX-FG-14', 'NEMAX', 100);

    $staleDispatched = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_BILLED, 'ORD-FG-E001', '2026-09-01');
    $staleRejected = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-E002', '2026-09-02');
    $pending = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-E003', '2026-09-03');
    fgStockAvailLine($staleDispatched, $product, 80);
    fgStockAvailLine($staleRejected, $product, 70);
    fgStockAvailLine($pending, $product, 40);

    Order::query()->whereKey($staleDispatched->id)->update([
        'dispatched_at' => now('Asia/Kolkata'),
        'dispatch_date' => now('Asia/Kolkata')->toDateString(),
    ]);
    Order::query()->whereKey($staleRejected->id)->update([
        'rejected_at' => now('Asia/Kolkata'),
    ]);

    $allocated = app(FinishedProductOrderAvailabilityService::class)->allocate([(int) $product->id]);

    expect($allocated)->not->toHaveKey($staleDispatched->id)
        ->and($allocated)->not->toHaveKey($staleRejected->id)
        ->and($allocated[$pending->id][$product->id])->toMatchArray([
            'allocated_to_earlier_orders' => 0.0,
            'available_for_this_order' => 40.0,
            'short_qty' => 0.0,
            'stock_status' => 'available',
        ]);
});

it('releases allocation immediately when an earlier order is reverted, dispatched, rejected, or cancelled', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000109');
    $dealer = fgStockAvailDealer($employee->id, '9300001109');
    $product = fgStockAvailProduct('NEMAX-FG-8', 'NEMAX', 100);

    $earlier = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-8001', '2026-09-01');
    $later = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-8002', '2026-09-02');
    fgStockAvailLine($earlier, $product, 70);
    fgStockAvailLine($later, $product, 50);

    $service = app(FinishedProductOrderAvailabilityService::class);

    expect($service->allocate([(int) $product->id])[$later->id][$product->id])->toMatchArray([
        'allocated_to_earlier_orders' => 70.0,
        'available_for_this_order' => 30.0,
        'short_qty' => 20.0,
        'stock_status' => 'partial_stock',
    ]);

    foreach ([
        Order::STATUS_REVERTED_TO_MANAGER,
        Order::STATUS_DISPATCHED,
        Order::STATUS_REJECTED,
        'cancelled',
    ] as $releasedStatus) {
        Order::query()->whereKey($earlier->id)->update(['status' => $releasedStatus]);

        expect($service->allocate([(int) $product->id]))->not->toHaveKey($earlier->id)
            ->and($service->allocate([(int) $product->id])[$later->id][$product->id])->toMatchArray([
                'allocated_to_earlier_orders' => 0.0,
                'available_for_this_order' => 50.0,
                'short_qty' => 0.0,
                'stock_status' => 'available',
            ]);

        Order::query()->whereKey($earlier->id)->update(['status' => Order::STATUS_APPROVED]);
    }

    Order::query()->whereKey($earlier->id)->update([
        'status' => Order::STATUS_BILLED,
        'dispatched_at' => now('Asia/Kolkata'),
    ]);

    expect($service->allocate([(int) $product->id]))->not->toHaveKey($earlier->id)
        ->and($service->allocate([(int) $product->id])[$later->id][$product->id])->toMatchArray([
            'allocated_to_earlier_orders' => 0.0,
            'available_for_this_order' => 50.0,
            'short_qty' => 0.0,
            'stock_status' => 'available',
        ]);
});

it('recalculates when stock increases or order quantity changes', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000104');
    $dealer = fgStockAvailDealer($employee->id, '9300001104');
    $product = fgStockAvailProduct('NEMAX-FG-4', 'NEMAX', 50);

    $first = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-4001', '2026-09-01');
    $second = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-4002', '2026-09-02');
    fgStockAvailLine($first, $product, 50);
    fgStockAvailLine($second, $product, 60);

    $service = app(FinishedProductOrderAvailabilityService::class);

    expect($service->allocate([(int) $product->id])[$second->id][$product->id]['short_qty'])->toBe(60.0);

    $product->update(['current_finished_stock' => 100]);

    expect($service->allocate([(int) $product->id])[$second->id][$product->id])->toMatchArray([
        'available_for_this_order' => 50.0,
        'short_qty' => 10.0,
        'short_label' => 'Short 10 Nos',
    ]);

    $second->items()->first()->update(['case_quantity' => 40]);

    expect($service->allocate([(int) $product->id])[$second->id][$product->id])->toMatchArray([
        'order_qty' => 40.0,
        'available_for_this_order' => 40.0,
        'short_qty' => 0.0,
        'stock_status' => 'available',
    ]);
});

it('includes live stock availability on production supervisor order detail and list', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000105');
    $production = fgStockAvailEmployee(UserRole::ProductionSupervisor, '9300000106');
    $dealer = fgStockAvailDealer($employee->id, '9300001105');
    $product = fgStockAvailProduct('NEMAX-FG-5', 'NEMAX', 100);

    $first = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-5001', '2026-09-01');
    $second = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-5002', '2026-09-02');
    fgStockAvailLine($first, $product, 50);
    fgStockAvailLine($second, $product, 60);

    $this->actingAs($production->user, 'sanctum')
        ->getJson("/api/production/orders/{$second->id}")
        ->assertOk()
        ->assertJsonPath('data.stock_availability_applies', true)
        ->assertJsonPath('data.stock_status', 'partial_stock')
        ->assertJsonPath('data.stock_status_label', 'Partial Stock')
        ->assertJsonPath('data.has_stock_shortage', true)
        ->assertJsonPath('data.stock_short_label', 'Short 10 Nos')
        ->assertJsonPath('data.stock_availability.0.order_qty', 60)
        ->assertJsonPath('data.stock_availability.0.current_finished_stock', 100)
        ->assertJsonPath('data.stock_availability.0.allocated_to_earlier_orders', 50)
        ->assertJsonPath('data.stock_availability.0.available_for_this_order', 50)
        ->assertJsonPath('data.stock_availability.0.short_qty', 10)
        ->assertJsonPath('data.stock_availability.0.short_label', 'Short 10 Nos');

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/orders?status=approved')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $second->id,
            'stock_availability_applies' => true,
            'stock_status' => 'partial_stock',
            'stock_status_label' => 'Partial Stock',
            'has_stock_shortage' => true,
            'stock_short_label' => 'Short 10 Nos',
        ]);
});

it('recalculates existing production supervisor orders after an earlier order is reverted', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000110');
    $production = fgStockAvailEmployee(UserRole::ProductionSupervisor, '9300000111');
    $dealer = fgStockAvailDealer($employee->id, '9300001110');
    $product = fgStockAvailProduct('NEMAX-FG-9', 'NEMAX', 100);

    $first = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-9001', '2026-09-01');
    $second = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-9002', '2026-09-02');
    fgStockAvailLine($first, $product, 50);
    fgStockAvailLine($second, $product, 60);

    $this->actingAs($production->user, 'sanctum')
        ->getJson("/api/production/orders/{$second->id}")
        ->assertOk()
        ->assertJsonPath('data.stock_availability.0.allocated_to_earlier_orders', 50)
        ->assertJsonPath('data.stock_availability.0.available_for_this_order', 50)
        ->assertJsonPath('data.stock_availability.0.short_qty', 10);

    $first->update(['status' => Order::STATUS_REVERTED_TO_MANAGER]);

    $this->actingAs($production->user, 'sanctum')
        ->getJson("/api/production/orders/{$second->id}")
        ->assertOk()
        ->assertJsonPath('data.stock_availability_applies', true)
        ->assertJsonPath('data.stock_availability.0.allocated_to_earlier_orders', 0)
        ->assertJsonPath('data.stock_availability.0.available_for_this_order', 60)
        ->assertJsonPath('data.stock_availability.0.short_qty', 0)
        ->assertJsonPath('data.stock_status', 'available');

    $this->actingAs($production->user, 'sanctum')
        ->getJson("/api/production/orders/{$first->id}")
        ->assertOk()
        ->assertJsonPath('data.stock_availability_applies', false);
});

it('recalculates existing production supervisor orders after an earlier billed order is dispatched without a status update', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000117');
    $production = fgStockAvailEmployee(UserRole::ProductionSupervisor, '9300000118');
    $dealer = fgStockAvailDealer($employee->id, '9300001117');
    $product = fgStockAvailProduct('NEMAX-FG-15', 'NEMAX', 100);

    $first = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_BILLED, 'ORD-FG-F001', '2026-09-01');
    $second = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-F002', '2026-09-02');
    fgStockAvailLine($first, $product, 50);
    fgStockAvailLine($second, $product, 60);

    $this->actingAs($production->user, 'sanctum')
        ->getJson("/api/production/orders/{$second->id}")
        ->assertOk()
        ->assertJsonPath('data.stock_availability.0.allocated_to_earlier_orders', 50)
        ->assertJsonPath('data.stock_availability.0.available_for_this_order', 50)
        ->assertJsonPath('data.stock_availability.0.short_qty', 10);

    Order::query()->whereKey($first->id)->update([
        'dispatched_at' => now('Asia/Kolkata'),
    ]);

    $this->actingAs($production->user, 'sanctum')
        ->getJson("/api/production/orders/{$second->id}")
        ->assertOk()
        ->assertJsonPath('data.stock_availability_applies', true)
        ->assertJsonPath('data.stock_availability.0.allocated_to_earlier_orders', 0)
        ->assertJsonPath('data.stock_availability.0.available_for_this_order', 60)
        ->assertJsonPath('data.stock_availability.0.short_qty', 0)
        ->assertJsonPath('data.stock_status', 'available');

    $this->actingAs($production->user, 'sanctum')
        ->getJson("/api/production/orders/{$first->id}")
        ->assertOk()
        ->assertJsonPath('data.stock_availability_applies', false);
});

it('reserves stock for placed, billed, and send-for-bill orders still in the pipeline', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000112');
    $dealer = fgStockAvailDealer($employee->id, '9300001112');
    $product = fgStockAvailProduct('NEMAX-FG-10', 'NEMAX', 100);

    $placed = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_PENDING_APPROVAL, 'ORD-FG-A001', '2026-09-01');
    $billed = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_BILLED, 'ORD-FG-A002', '2026-09-02');
    $sentForBill = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_PENDING_FOR_BILLING, 'ORD-FG-A003', '2026-09-03');
    $later = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-A004', '2026-09-04');
    fgStockAvailLine($placed, $product, 20);
    fgStockAvailLine($billed, $product, 25);
    fgStockAvailLine($sentForBill, $product, 15);
    fgStockAvailLine($later, $product, 50);

    $allocated = app(FinishedProductOrderAvailabilityService::class)->allocate([(int) $product->id]);

    expect($allocated[$placed->id][$product->id]['available_for_this_order'])->toBe(20.0)
        ->and($allocated[$billed->id][$product->id])->toMatchArray([
            'allocated_to_earlier_orders' => 20.0,
            'available_for_this_order' => 25.0,
        ])
        ->and($allocated[$sentForBill->id][$product->id])->toMatchArray([
            'allocated_to_earlier_orders' => 45.0,
            'available_for_this_order' => 15.0,
        ])
        ->and($allocated[$later->id][$product->id])->toMatchArray([
            'allocated_to_earlier_orders' => 60.0,
            'available_for_this_order' => 40.0,
            'short_qty' => 10.0,
            'stock_status' => 'partial_stock',
        ]);
});

it('reserves stock for on-hold orders that are still valid for production', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000113');
    $dealer = fgStockAvailDealer($employee->id, '9300001113');
    $product = fgStockAvailProduct('NEMAX-FG-11', 'NEMAX', 100);

    $held = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_ON_HOLD, 'ORD-FG-B001', '2026-09-01');
    $later = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-B002', '2026-09-02');
    fgStockAvailLine($held, $product, 40);
    fgStockAvailLine($later, $product, 70);

    $allocated = app(FinishedProductOrderAvailabilityService::class)->allocate([(int) $product->id]);

    expect($allocated[$held->id][$product->id])->toMatchArray([
        'allocated_to_earlier_orders' => 0.0,
        'available_for_this_order' => 40.0,
        'stock_status' => 'available',
    ])->and($allocated[$later->id][$product->id])->toMatchArray([
        'allocated_to_earlier_orders' => 40.0,
        'available_for_this_order' => 60.0,
        'short_qty' => 10.0,
        'stock_status' => 'partial_stock',
    ]);
});

it('allocates same-day orders by order number and date before id', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000114');
    $dealer = fgStockAvailDealer($employee->id, '9300001114');
    $product = fgStockAvailProduct('NEMAX-FG-12', 'NEMAX', 100);

    $laterNo = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-C002', '2026-09-01');
    $earlierNo = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-C001', '2026-09-01');
    $olderDateLaterId = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-C003', '2026-08-31');
    fgStockAvailLine($laterNo, $product, 30);
    fgStockAvailLine($earlierNo, $product, 20);
    fgStockAvailLine($olderDateLaterId, $product, 40);

    $allocated = app(FinishedProductOrderAvailabilityService::class)->allocate([(int) $product->id]);

    expect($allocated[$olderDateLaterId->id][$product->id])->toMatchArray([
        'allocated_to_earlier_orders' => 0.0,
        'available_for_this_order' => 40.0,
    ])->and($allocated[$earlierNo->id][$product->id])->toMatchArray([
        'allocated_to_earlier_orders' => 40.0,
        'available_for_this_order' => 20.0,
    ])->and($allocated[$laterNo->id][$product->id])->toMatchArray([
        'allocated_to_earlier_orders' => 60.0,
        'available_for_this_order' => 30.0,
        'short_qty' => 0.0,
    ]);
});

it('does not double-count stock across multiple orders or duplicate lines', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000115');
    $dealer = fgStockAvailDealer($employee->id, '9300001115');
    $product = fgStockAvailProduct('NEMAX-FG-13', 'NEMAX', 100);

    $first = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-D001', '2026-09-01');
    $second = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-D002', '2026-09-02');
    $third = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-D003', '2026-09-03');
    fgStockAvailLine($first, $product, 30);
    fgStockAvailLine($first, $product, 20);
    fgStockAvailLine($second, $product, 40);
    fgStockAvailLine($third, $product, 50);

    $allocated = app(FinishedProductOrderAvailabilityService::class)->allocate([(int) $product->id]);
    $firstRow = $allocated[$first->id][$product->id];
    $secondRow = $allocated[$second->id][$product->id];
    $thirdRow = $allocated[$third->id][$product->id];

    expect($firstRow)->toMatchArray([
        'order_qty' => 50.0,
        'allocated_to_earlier_orders' => 0.0,
        'available_for_this_order' => 50.0,
    ])->and($secondRow)->toMatchArray([
        'allocated_to_earlier_orders' => 50.0,
        'available_for_this_order' => 40.0,
        'short_qty' => 0.0,
    ])->and($thirdRow)->toMatchArray([
        'allocated_to_earlier_orders' => 90.0,
        'available_for_this_order' => 10.0,
        'short_qty' => 40.0,
        'stock_status' => 'partial_stock',
    ]);

    $reserved = $firstRow['available_for_this_order']
        + $secondRow['available_for_this_order']
        + $thirdRow['available_for_this_order'];

    expect($reserved)->toBe(100.0)
        ->and($secondRow['allocated_to_earlier_orders'])->not->toBe(90.0)
        ->and($thirdRow['allocated_to_earlier_orders'])->not->toBe(140.0);
});

it('lets a pending approval order consume stock ahead of a later approved order', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000107');
    $dealer = fgStockAvailDealer($employee->id, '9300001107');
    $product = fgStockAvailProduct('NEMAX-FG-6', 'NEMAX', 100);

    $pending = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_PENDING_APPROVAL, 'ORD-FG-6001', '2026-09-01');
    $approved = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-6002', '2026-09-02');
    fgStockAvailLine($pending, $product, 80);
    fgStockAvailLine($approved, $product, 50);

    $allocated = app(FinishedProductOrderAvailabilityService::class)->allocate([(int) $product->id]);

    expect($allocated[$pending->id][$product->id]['available_for_this_order'])->toBe(80.0)
        ->and($allocated[$approved->id][$product->id])->toMatchArray([
            'available_for_this_order' => 20.0,
            'short_qty' => 30.0,
            'stock_status' => 'partial_stock',
            'short_label' => 'Short 30 Nos',
        ]);
});
