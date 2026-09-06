<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Dealer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Orders\FinishedProductOrderAvailabilityService;

function fgStockAvailEmployee(UserRole $role, string $mobile): \App\Models\Employee
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

it('drops dispatched and rejected orders from the virtual allocation queue', function () {
    $employee = fgStockAvailEmployee(UserRole::Employee, '9300000103');
    $dealer = fgStockAvailDealer($employee->id, '9300001103');
    $product = fgStockAvailProduct('NEMAX-FG-3', 'NEMAX', 100);

    $dispatched = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_DISPATCHED, 'ORD-FG-3001', '2026-09-01');
    $rejected = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_REJECTED, 'ORD-FG-3002', '2026-09-02');
    $pending = fgStockAvailOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-FG-3003', '2026-09-03');
    fgStockAvailLine($dispatched, $product, 80);
    fgStockAvailLine($rejected, $product, 70);
    fgStockAvailLine($pending, $product, 60);

    $allocated = app(FinishedProductOrderAvailabilityService::class)->allocate([(int) $product->id]);

    expect($allocated)->not->toHaveKey($dispatched->id)
        ->and($allocated)->not->toHaveKey($rejected->id)
        ->and($allocated[$pending->id][$product->id])->toMatchArray([
            'available_for_this_order' => 60.0,
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
