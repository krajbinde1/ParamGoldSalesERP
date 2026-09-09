<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Orders\DispatchOrder;
use App\Actions\Orders\DispatchOrderWithTransport;
use App\Actions\Orders\RejectOrderWithRemarks;
use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Models\Dealer;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\OrderDispatchStockService;
use App\Services\Orders\FinishedProductOrderAvailabilityService;
use Illuminate\Support\Collection;

function fgDispatchEmployee(UserRole $role, string $mobile): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $role->label().' Dsp '.$mobile,
        'mobile' => $mobile,
        'email' => str_replace('_', '.', $role->value).'.'.$mobile.'.fgdsp@example.com',
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

function fgDispatchDealer(int $employeeId, string $mobile): Dealer
{
    return Dealer::query()->create([
        'firm_name' => 'FG Dispatch Dealer '.$mobile,
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

function fgDispatchProduct(string $code, float $stock): Product
{
    return Product::query()->create([
        'product_code' => $code,
        'product_name' => 'SAMRUDDHI PLUS 5KG '.$code,
        'category' => 'General',
        'dealer_price' => 10,
        'gst_percentage' => 18,
        'uom' => 'Nos',
        'production_unit' => 'Nos',
        'nos_per_case' => 1,
        'status' => true,
        'manufacturing_enabled' => true,
        'current_finished_stock' => $stock,
        'weighted_average_cost' => 25,
    ]);
}

function fgDispatchOrder(
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

function fgDispatchLine(Order $order, Product $product, int $qty): void
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

function fgDispatchLedgers(int $orderId, int $productId): Collection
{
    return StockLedger::query()
        ->where('item_type', StockItemType::FinishedProduct)
        ->where('reference_type', Order::class)
        ->where('reference_id', $orderId)
        ->where('product_id', $productId)
        ->orderBy('id')
        ->get();
}

it('deducts finished stock once when a billed order is dispatched', function () {
    $employee = fgDispatchEmployee(UserRole::Employee, '9400000101');
    $production = fgDispatchEmployee(UserRole::ProductionSupervisor, '9400000102');
    $dealer = fgDispatchDealer($employee->id, '9400001101');
    $product = fgDispatchProduct('DSP-FG-1', 36);

    $order = fgDispatchOrder($employee->id, $dealer->id, Order::STATUS_BILLED, 'ORD-DSP-1001', '2026-09-01');
    fgDispatchLine($order, $product, 20);

    app(DispatchOrder::class)->execute(
        order: $order->fresh(),
        actor: $production->user,
        remark: 'Loaded',
    );

    expect((float) $product->fresh()->current_finished_stock)->toBe(16.0);

    $ledgers = fgDispatchLedgers($order->id, $product->id);
    expect($ledgers)->toHaveCount(1)
        ->and($ledgers->first()->transaction_type)->toBe(StockTransactionType::Dispatch)
        ->and((float) $ledgers->first()->quantity_out)->toBe(20.0)
        ->and((float) $ledgers->first()->quantity_in)->toBe(0.0)
        ->and((float) $ledgers->first()->stock_after)->toBe(16.0);

    app(OrderDispatchStockService::class)->postForDispatchedOrder($order->fresh(), $production->user);

    expect((float) $product->fresh()->current_finished_stock)->toBe(16.0)
        ->and(fgDispatchLedgers($order->id, $product->id))->toHaveCount(1);
});

it('does not deduct finished stock for rejected or cancelled orders', function () {
    $employee = fgDispatchEmployee(UserRole::Employee, '9400000103');
    $dealer = fgDispatchDealer($employee->id, '9400001103');
    $product = fgDispatchProduct('DSP-FG-2', 36);

    $rejected = fgDispatchOrder($employee->id, $dealer->id, Order::STATUS_REJECTED, 'ORD-DSP-2001', '2026-09-01');
    $cancelled = fgDispatchOrder($employee->id, $dealer->id, 'cancelled', 'ORD-DSP-2002', '2026-09-02');
    fgDispatchLine($rejected, $product, 20);
    fgDispatchLine($cancelled, $product, 10);

    $service = app(OrderDispatchStockService::class);
    $service->postForDispatchedOrder($rejected->fresh());
    $service->postForDispatchedOrder($cancelled->fresh());

    expect((float) $product->fresh()->current_finished_stock)->toBe(36.0)
        ->and(fgDispatchLedgers($rejected->id, $product->id))->toHaveCount(0)
        ->and(fgDispatchLedgers($cancelled->id, $product->id))->toHaveCount(0);
});

it('restores finished stock when a dispatched order is rejected and does not restore twice', function () {
    $employee = fgDispatchEmployee(UserRole::Employee, '9400000104');
    $production = fgDispatchEmployee(UserRole::ProductionSupervisor, '9400000105');
    $admin = User::query()->create([
        'name' => 'FG Dispatch Admin',
        'email' => 'admin.fgdsp.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
    $dealer = fgDispatchDealer($employee->id, '9400001104');
    $product = fgDispatchProduct('DSP-FG-3', 36);

    $order = fgDispatchOrder($employee->id, $dealer->id, Order::STATUS_BILLED, 'ORD-DSP-3001', '2026-09-01');
    fgDispatchLine($order, $product, 20);

    app(DispatchOrder::class)->execute(
        order: $order->fresh(),
        actor: $production->user,
    );

    expect((float) $product->fresh()->current_finished_stock)->toBe(16.0);

    app(RejectOrderWithRemarks::class)->execute(
        order: $order->fresh(),
        actor: $admin,
        remark: 'Dealer refused delivery',
        rejectedByRole: Order::REJECTED_BY_ROLE_ADMIN,
    );

    expect((float) $product->fresh()->current_finished_stock)->toBe(36.0);

    app(OrderDispatchStockService::class)->reverseForRejectedOrder($order->fresh(), $admin);

    expect((float) $product->fresh()->current_finished_stock)->toBe(36.0)
        ->and(
            StockLedger::query()
                ->where('reference_id', $order->id)
                ->where('transaction_type', StockTransactionType::Return)
                ->count()
        )->toBe(1);
});

it('posts missing dispatch stock for an already dispatched order without a ledger and never repeats it', function () {
    $employee = fgDispatchEmployee(UserRole::Employee, '9400000106');
    $dealer = fgDispatchDealer($employee->id, '9400001106');
    $product = fgDispatchProduct('DSP-FG-4', 36);

    $order = fgDispatchOrder($employee->id, $dealer->id, Order::STATUS_DISPATCHED, 'ORD-DSP-4001', '2026-09-01');
    $order->forceFill(['dispatched_at' => now('Asia/Kolkata')])->saveQuietly();
    fgDispatchLine($order, $product, 20);

    $service = app(OrderDispatchStockService::class);
    $first = $service->postMissingForDispatchedOrders((int) $order->id);
    $second = $service->postMissingForDispatchedOrders((int) $order->id);

    expect($first['posted'])->toBe(1)
        ->and($second['posted'])->toBe(0)
        ->and((float) $product->fresh()->current_finished_stock)->toBe(16.0)
        ->and(fgDispatchLedgers($order->id, $product->id))->toHaveCount(1);
});

it('uses remaining finished stock after dispatch for FIFO pending allocation', function () {
    $employee = fgDispatchEmployee(UserRole::Employee, '9400000107');
    $production = fgDispatchEmployee(UserRole::ProductionSupervisor, '9400000108');
    $dealer = fgDispatchDealer($employee->id, '9400001107');
    $product = fgDispatchProduct('DSP-FG-5', 36);

    $dispatched = fgDispatchOrder($employee->id, $dealer->id, Order::STATUS_BILLED, 'ORD-DSP-5001', '2026-09-01');
    $firstPending = fgDispatchOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-DSP-5002', '2026-09-02');
    $secondPending = fgDispatchOrder($employee->id, $dealer->id, Order::STATUS_APPROVED, 'ORD-DSP-5003', '2026-09-03');
    fgDispatchLine($dispatched, $product, 20);
    fgDispatchLine($firstPending, $product, 30);
    fgDispatchLine($secondPending, $product, 10);

    app(DispatchOrder::class)->execute(
        order: $dispatched->fresh(),
        actor: $production->user,
    );

    $allocated = app(FinishedProductOrderAvailabilityService::class)->allocate([(int) $product->id]);

    expect((float) $product->fresh()->current_finished_stock)->toBe(16.0)
        ->and($allocated)->not->toHaveKey($dispatched->id)
        ->and($allocated[$firstPending->id][$product->id])->toMatchArray([
            'current_finished_stock' => 16.0,
            'allocated_to_earlier_orders' => 0.0,
            'remaining_before_this_order' => 16.0,
            'allocated_to_this_order' => 16.0,
            'available_for_this_order' => 16.0,
            'short_qty' => 14.0,
            'stock_status' => 'short',
        ])
        ->and($allocated[$secondPending->id][$product->id])->toMatchArray([
            'current_finished_stock' => 16.0,
            'allocated_to_earlier_orders' => 30.0,
            'remaining_before_this_order' => 0.0,
            'allocated_to_this_order' => 0.0,
            'available_for_this_order' => 0.0,
            'short_qty' => 10.0,
            'stock_status' => 'short',
        ]);
});

it('converts cases to nos when total_quantity_nos is zero and dry-run does not write', function () {
    $employee = fgDispatchEmployee(UserRole::Employee, '9400000111');
    $dealer = fgDispatchDealer($employee->id, '9400001111');
    $product = fgDispatchProduct('DSP-FG-7', 46);
    $product->forceFill(['nos_per_case' => 1])->save();

    $order = fgDispatchOrder($employee->id, $dealer->id, Order::STATUS_DISPATCHED, 'ORD-DSP-7001', '2026-09-06');
    $order->forceFill([
        'dispatched_at' => now('Asia/Kolkata'),
        'dispatch_date' => '2026-09-06',
        'order_no' => 'PG-20260906-0001',
    ])->saveQuietly();
    $item = $order->items()->make([
        'product_id' => $product->id,
        'case_quantity' => 10,
        'nos_per_case' => 1,
        'total_quantity_nos' => 0,
        'quantity' => 0,
        'unit' => 'Nos',
        'rate_per_no' => 10,
        'rate' => 10,
        'discount_percentage' => 0,
        'discount_amount' => 0,
        'gst_percentage' => 18,
        'base_amount' => 100,
        'taxable_amount' => 100,
        'gst_amount' => 0,
        'final_amount' => 100,
        'line_total' => 100,
    ]);
    $item->saveQuietly();

    $service = app(OrderDispatchStockService::class);
    $audit = $service->auditMissing((int) $order->id);

    expect($audit['missing_lines'])->toBe(1)
        ->and($audit['missing'][0]['qty_nos'])->toEqual(10.0)
        ->and($audit['missing'][0]['product_id'])->toBe($product->id)
        ->and(fgDispatchLedgers($order->id, $product->id))->toHaveCount(0)
        ->and((float) $product->fresh()->current_finished_stock)->toBe(46.0);

    $first = $service->postMissingForDispatchedOrders((int) $order->id);
    $second = $service->postMissingForDispatchedOrders((int) $order->id);

    expect($first['posted'])->toBe(1)
        ->and($second['posted'])->toBe(0)
        ->and((float) $product->fresh()->current_finished_stock)->toBe(36.0)
        ->and((float) fgDispatchLedgers($order->id, $product->id)->first()->quantity_out)->toBe(10.0);
});

it('deducts finished stock when dispatching with transport details', function () {
    $employee = fgDispatchEmployee(UserRole::Employee, '9400000109');
    $production = fgDispatchEmployee(UserRole::ProductionSupervisor, '9400000110');
    $dealer = fgDispatchDealer($employee->id, '9400001109');
    $product = fgDispatchProduct('DSP-FG-6', 36);

    $order = fgDispatchOrder($employee->id, $dealer->id, Order::STATUS_BILLED, 'ORD-DSP-6001', '2026-09-01');
    fgDispatchLine($order, $product, 20);

    app(DispatchOrderWithTransport::class)->execute(
        order: $order->fresh(),
        actor: $production->user,
        transportType: 'company_transport',
        transportAmount: 50,
    );

    expect((float) $product->fresh()->current_finished_stock)->toBe(16.0)
        ->and(fgDispatchLedgers($order->id, $product->id))->toHaveCount(1);
});
