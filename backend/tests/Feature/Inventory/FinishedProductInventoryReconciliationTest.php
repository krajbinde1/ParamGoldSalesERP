<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Orders\DispatchOrder;
use App\Actions\Orders\RejectOrderWithRemarks;
use App\Enums\BomStatus;
use App\Enums\ProductionBatchStatus;
use App\Enums\StockAdjustmentType;
use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Models\Bom;
use App\Models\Dealer;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\FinishedProductInventoryAuditService;
use App\Services\Inventory\FinishedProductStockBalanceService;
use App\Services\Inventory\InventoryReportService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\OrderDispatchStockService;
use App\Services\Inventory\StockItemLedgerService;
use App\Services\Inventory\StockLedgerService;
use App\Services\Orders\FinishedProductOrderAvailabilityService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

function fgRecMobile(string $seed): string
{
    $digits = preg_replace('/\D/', '', md5($seed)) ?? '1';

    return '97'.substr(str_pad($digits, 8, '0'), 0, 8);
}

function fgRecEmployee(UserRole $role, string $seed): Employee
{
    $mobile = fgRecMobile($seed.$role->value);

    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $role->label().' Rec '.$mobile,
        'mobile' => $mobile,
        'email' => str_replace('_', '.', $role->value).'.'.$mobile.'.fgrec@example.com',
        'department' => 'Sales',
        'designation' => $role->label(),
        'joining_date' => '2026-07-01',
        'salary' => 25000,
        'base_location' => 'Aurangabad',
        'daily_allowance' => 300,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => '234567'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
        'pan_number' => 'AAAAA'.str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT).'Z',
        'bank_name' => 'Test Bank',
        'account_number' => (string) random_int(100000000000, 999999999999),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => $role->value,
    ])->employee;
}

function fgRecDealer(int $employeeId, string $seed): Dealer
{
    $mobile = fgRecMobile('dealer'.$seed);

    return Dealer::query()->create([
        'firm_name' => 'FG Rec Dealer '.$mobile,
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

function fgRecProduct(string $suffix, float $stock): Product
{
    return Product::query()->create([
        'product_code' => 'REC-'.$suffix,
        'product_name' => 'FG REC '.$suffix,
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

function fgRecOpening(Product $product, float $qty, string $date = '2026-09-06'): void
{
    StockLedger::query()->create([
        'transaction_date' => $date,
        'transaction_type' => StockTransactionType::OpeningStock,
        'item_type' => StockItemType::FinishedProduct,
        'product_id' => $product->id,
        'quantity_in' => $qty,
        'quantity_out' => 0,
        'stock_before' => 0,
        'stock_after' => $qty,
        'rate' => 25,
        'transaction_value' => $qty * 25,
        'closing_value' => $qty * 25,
        'remarks' => 'Opening Stock',
    ]);
    $product->forceFill(['current_finished_stock' => $qty, 'opening_finished_stock' => $qty])->save();
}

function fgRecOrder(int $employeeId, int $dealerId, string $status, string $orderNo, string $date): Order
{
    return Order::query()->create([
        'order_no' => $orderNo,
        'order_date' => $date,
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

function fgRecLine(Order $order, Product $product, int $qty): void
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

function fgRecMarkDispatched(Order $order, string $date): void
{
    $order->forceFill([
        'status' => Order::STATUS_DISPATCHED,
        'dispatched_at' => Carbon::parse($date.' 11:00:00', 'Asia/Kolkata'),
        'dispatch_date' => $date,
    ])->saveQuietly();
}

function fgRecMove(Product $product, string $date, StockTransactionType $type, float $in, float $out, ?User $actor = null): StockLedger
{
    return app(StockLedgerService::class)->postFinishedProductMovement(
        $product->fresh(),
        $in,
        $out,
        25,
        [
            'transaction_date' => $date,
            'transaction_type' => $type,
            'remarks' => $type->value,
            'allow_negative_stock' => $out > 0,
        ],
        $actor,
    );
}

function fgRecWorld(string $seed): array
{
    $employee = fgRecEmployee(UserRole::Employee, $seed);
    $production = fgRecEmployee(UserRole::ProductionSupervisor, $seed.'p');
    $dealer = fgRecDealer($employee->id, $seed);
    $admin = User::query()->create([
        'name' => 'FG Rec Admin '.$seed,
        'email' => 'admin.fgrec.'.$seed.'.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);

    return compact('employee', 'production', 'dealer', 'admin');
}

it('reconstructs opening-only finished stock', function () {
    $product = fgRecProduct('OPEN-'.uniqid(), 0);
    fgRecOpening($product, 40);

    $effect = app(OrderDispatchStockService::class)->auditMissing(null, [$product->product_name], true)['product_effects'][0];

    expect($effect['opening_qty'])->toEqual(40.0)
        ->and($effect['production_inward_qty'])->toEqual(0.0)
        ->and($effect['confirmed_dispatch_qty'])->toEqual(0.0)
        ->and($effect['expected_current_stock'])->toEqual(40.0)
        ->and($effect['erp_current_stock'])->toEqual(40.0)
        ->and($effect['status'])->toBe('MATCH');
});

it('adds production after opening', function () {
    $product = fgRecProduct('PROD-'.uniqid(), 0);
    fgRecOpening($product, 40);
    fgRecMove($product, '2026-09-07', StockTransactionType::ProductionOutput, 10, 0);

    $effect = app(OrderDispatchStockService::class)->auditMissing(null, [$product->product_name], true)['product_effects'][0];

    expect($effect['production_inward_qty'])->toEqual(10.0)
        ->and($effect['expected_current_stock'])->toEqual(50.0)
        ->and((float) $product->fresh()->current_finished_stock)->toBe(50.0);
});

it('adds purchase inward after opening', function () {
    $product = fgRecProduct('INW-'.uniqid(), 0);
    fgRecOpening($product, 20);
    fgRecMove($product, '2026-09-07', StockTransactionType::Purchase, 5, 0);

    $effect = app(OrderDispatchStockService::class)->auditMissing(null, [$product->product_name], true)['product_effects'][0];

    expect($effect['production_inward_qty'])->toEqual(5.0)
        ->and($effect['expected_current_stock'])->toEqual(25.0)
        ->and((float) $product->fresh()->current_finished_stock)->toBe(25.0);
});

it('deducts same-day dispatch after opening', function () {
    $world = fgRecWorld('sameday');
    $product = fgRecProduct('SAME-'.uniqid(), 0);
    fgRecOpening($product, 34);

    $order = fgRecOrder($world['employee']->id, $world['dealer']->id, Order::STATUS_DISPATCHED, 'REC-SAME-1', '2026-09-06');
    fgRecMarkDispatched($order, '2026-09-06');
    fgRecLine($order, $product, 10);

    $posted = app(OrderDispatchStockService::class)->postMissingForDispatchedOrders((int) $order->id);

    expect($posted['posted'])->toBe(1)
        ->and((float) $product->fresh()->current_finished_stock)->toBe(24.0);
});

it('deducts a later dispatch after opening', function () {
    $world = fgRecWorld('later');
    $product = fgRecProduct('LATER-'.uniqid(), 0);
    fgRecOpening($product, 34);

    $order = fgRecOrder($world['employee']->id, $world['dealer']->id, Order::STATUS_DISPATCHED, 'REC-LATER-1', '2026-09-08');
    fgRecMarkDispatched($order, '2026-09-08');
    fgRecLine($order, $product, 4);

    app(OrderDispatchStockService::class)->postMissingForDispatchedOrders((int) $order->id);

    expect((float) $product->fresh()->current_finished_stock)->toBe(30.0);
});

it('posts multiple historical dispatches once each', function () {
    $world = fgRecWorld('multi');
    $product = fgRecProduct('MULTI-'.uniqid(), 0);
    fgRecOpening($product, 50);

    $first = fgRecOrder($world['employee']->id, $world['dealer']->id, Order::STATUS_DISPATCHED, 'REC-MULTI-1', '2026-09-06');
    fgRecMarkDispatched($first, '2026-09-06');
    fgRecLine($first, $product, 10);

    $second = fgRecOrder($world['employee']->id, $world['dealer']->id, Order::STATUS_DISPATCHED, 'REC-MULTI-2', '2026-09-07');
    fgRecMarkDispatched($second, '2026-09-07');
    fgRecLine($second, $product, 6);

    $service = app(OrderDispatchStockService::class);
    $firstRun = $service->postMissingForDispatchedOrders(null, [$product->product_name]);
    $secondRun = $service->postMissingForDispatchedOrders(null, [$product->product_name]);

    expect($firstRun['posted'])->toBe(2)
        ->and($secondRun['posted'])->toBe(0)
        ->and((float) $product->fresh()->current_finished_stock)->toBe(34.0);
});

it('applies production then dispatch', function () {
    $world = fgRecWorld('proddisp');
    $product = fgRecProduct('PD-'.uniqid(), 0);
    fgRecOpening($product, 10);
    fgRecMove($product, '2026-09-07', StockTransactionType::ProductionOutput, 20, 0);

    $order = fgRecOrder($world['employee']->id, $world['dealer']->id, Order::STATUS_DISPATCHED, 'REC-PD-1', '2026-09-08');
    fgRecMarkDispatched($order, '2026-09-08');
    fgRecLine($order, $product, 12);
    app(OrderDispatchStockService::class)->postMissingForDispatchedOrders((int) $order->id);

    expect((float) $product->fresh()->current_finished_stock)->toBe(18.0);
});

it('applies dispatch then later production', function () {
    $world = fgRecWorld('dispprod');
    $product = fgRecProduct('DP-'.uniqid(), 0);
    fgRecOpening($product, 10);

    $order = fgRecOrder($world['employee']->id, $world['dealer']->id, Order::STATUS_DISPATCHED, 'REC-DP-1', '2026-09-06');
    fgRecMarkDispatched($order, '2026-09-06');
    fgRecLine($order, $product, 4);
    app(OrderDispatchStockService::class)->postMissingForDispatchedOrders((int) $order->id);
    fgRecMove($product->fresh(), '2026-09-08', StockTransactionType::ProductionOutput, 9, 0);

    expect((float) $product->fresh()->current_finished_stock)->toBe(15.0);
});

it('posts historical dispatch into negative stock and does not clamp to zero', function () {
    $world = fgRecWorld('neg');
    $product = fgRecProduct('NEG-'.uniqid(), 0);
    fgRecOpening($product, 34);

    $order = fgRecOrder($world['employee']->id, $world['dealer']->id, Order::STATUS_DISPATCHED, 'REC-NEG-1', '2026-09-06');
    fgRecMarkDispatched($order, '2026-09-06');
    fgRecLine($order, $product, 35);
    app(OrderDispatchStockService::class)->postMissingForDispatchedOrders((int) $order->id);

    $product = $product->fresh();
    $ledger = StockLedger::query()
        ->where('product_id', $product->id)
        ->where('transaction_type', StockTransactionType::Dispatch)
        ->first();
    $itemLedger = app(StockItemLedgerService::class)->build([
        'item_type' => StockItemType::FinishedProduct->value,
        'item_id' => $product->id,
        'from' => '2026-09-06',
        'to' => '2026-09-09',
    ]);
    $report = app(InventoryReportService::class)->build([
        'inventory_type' => InventoryReportService::TYPE_FINISHED_PRODUCT,
        'item_key' => InventoryReportService::TYPE_FINISHED_PRODUCT.':'.$product->id,
    ]);
    $reportRow = (clone $report->query)->where('item_id', $product->id)->first();
    $pending = fgRecOrder($world['employee']->id, $world['dealer']->id, Order::STATUS_APPROVED, 'REC-NEG-PEND', '2026-09-09');
    fgRecLine($pending, $product, 2);
    $fifo = app(FinishedProductOrderAvailabilityService::class)->allocate([(int) $product->id]);
    $synced = app(FinishedProductStockBalanceService::class)->syncFromLedgers($product->fresh());

    expect((float) $product->current_finished_stock)->toBe(-1.0)
        ->and((float) $ledger->stock_after)->toBe(-1.0)
        ->and((float) $itemLedger->totals['closing_qty'])->toBe(-1.0)
        ->and((float) $reportRow->current_stock)->toBe(-1.0)
        ->and((float) $synced->current_finished_stock)->toBe(-1.0)
        ->and($fifo[$pending->id][$product->id]['current_finished_stock'])->toEqual(-1.0)
        ->and($itemLedger->header['warning'] ?? null)->toBeNull();
});

it('blocks a new dispatch when finished stock is insufficient', function () {
    $world = fgRecWorld('block');
    $product = fgRecProduct('BLK-'.uniqid(), 5);
    $order = fgRecOrder($world['employee']->id, $world['dealer']->id, Order::STATUS_BILLED, 'REC-BLK-1', '2026-09-09');
    fgRecLine($order, $product, 20);

    expect(fn () => app(DispatchOrder::class)->execute(
        order: $order->fresh(),
        actor: $world['production']->user,
    ))->toThrow(ValidationException::class);

    expect($order->fresh()->status)->toBe(Order::STATUS_BILLED)
        ->and((float) $product->fresh()->current_finished_stock)->toBe(5.0);
});

it('never double-posts the same order and product dispatch outward', function () {
    $world = fgRecWorld('idemp');
    $product = fgRecProduct('ID-'.uniqid(), 20);
    $order = fgRecOrder($world['employee']->id, $world['dealer']->id, Order::STATUS_BILLED, 'REC-ID-1', '2026-09-09');
    fgRecLine($order, $product, 8);

    app(DispatchOrder::class)->execute(order: $order->fresh(), actor: $world['production']->user);
    app(OrderDispatchStockService::class)->postForDispatchedOrder($order->fresh(), $world['production']->user);
    app(OrderDispatchStockService::class)->postMissingForDispatchedOrders((int) $order->id);

    expect(StockLedger::query()
        ->where('reference_id', $order->id)
        ->where('product_id', $product->id)
        ->where('transaction_type', StockTransactionType::Dispatch)
        ->count())->toBe(1);
});

it('reverses dispatch stock exactly once when the order is rejected', function () {
    $world = fgRecWorld('rej');
    $product = fgRecProduct('REJ-'.uniqid(), 20);
    $order = fgRecOrder($world['employee']->id, $world['dealer']->id, Order::STATUS_BILLED, 'REC-REJ-1', '2026-09-09');
    fgRecLine($order, $product, 8);

    app(DispatchOrder::class)->execute(order: $order->fresh(), actor: $world['production']->user);
    app(RejectOrderWithRemarks::class)->execute(
        order: $order->fresh(),
        actor: $world['admin'],
        remark: 'Refused',
        rejectedByRole: Order::REJECTED_BY_ROLE_ADMIN,
    );
    app(OrderDispatchStockService::class)->reverseForRejectedOrder($order->fresh(), $world['admin']);

    expect((float) $product->fresh()->current_finished_stock)->toBe(20.0)
        ->and(StockLedger::query()
            ->where('reference_id', $order->id)
            ->where('transaction_type', StockTransactionType::Return)
            ->count())->toBe(1);
});

it('applies positive and negative finished-product adjustments', function () {
    $world = fgRecWorld('adj');
    $product = fgRecProduct('ADJ-'.uniqid(), 0);
    fgRecOpening($product, 20);

    app(InventoryService::class)->adjustStock([
        'item_type' => StockItemType::FinishedProduct->value,
        'product_id' => $product->id,
        'adjustment_type' => StockAdjustmentType::StockIncrease->value,
        'adjusted_quantity' => 3,
        'adjustment_date' => '2026-09-07',
        'reason' => 'Found',
    ], $world['admin']);
    app(InventoryService::class)->adjustStock([
        'item_type' => StockItemType::FinishedProduct->value,
        'product_id' => $product->id,
        'adjustment_type' => StockAdjustmentType::StockDecrease->value,
        'adjusted_quantity' => 2,
        'adjustment_date' => '2026-09-08',
        'reason' => 'Lost',
    ], $world['admin']);

    $effect = app(OrderDispatchStockService::class)->auditMissing(null, [$product->product_name], true)['product_effects'][0];

    expect($effect['returns_positive_qty'])->toEqual(3.0)
        ->and($effect['outward_negative_adj_qty'])->toEqual(2.0)
        ->and($effect['expected_current_stock'])->toEqual(21.0)
        ->and((float) $product->fresh()->current_finished_stock)->toBe(21.0);
});

it('adds a sales return to finished stock', function () {
    $world = fgRecWorld('ret');
    $product = fgRecProduct('RET-'.uniqid(), 0);
    fgRecOpening($product, 10);

    app(InventoryService::class)->adjustStock([
        'item_type' => StockItemType::FinishedProduct->value,
        'product_id' => $product->id,
        'adjustment_type' => StockAdjustmentType::Return->value,
        'adjusted_quantity' => 4,
        'adjustment_date' => '2026-09-07',
        'reason' => 'Dealer return',
    ], $world['admin']);

    $effect = app(OrderDispatchStockService::class)->auditMissing(null, [$product->product_name], true)['product_effects'][0];

    expect($effect['returns_positive_qty'])->toEqual(4.0)
        ->and($effect['expected_current_stock'])->toEqual(14.0)
        ->and((float) $product->fresh()->current_finished_stock)->toBe(14.0);
});

it('posts dispatch outwards for every product on a multi-product order', function () {
    $world = fgRecWorld('twoprod');
    $first = fgRecProduct('P1-'.uniqid(), 20);
    $second = fgRecProduct('P2-'.uniqid(), 15);
    $order = fgRecOrder($world['employee']->id, $world['dealer']->id, Order::STATUS_BILLED, 'REC-TWO-1', '2026-09-09');
    fgRecLine($order, $first, 5);
    fgRecLine($order, $second, 3);

    app(DispatchOrder::class)->execute(order: $order->fresh(), actor: $world['production']->user);

    expect((float) $first->fresh()->current_finished_stock)->toBe(15.0)
        ->and((float) $second->fresh()->current_finished_stock)->toBe(12.0)
        ->and(StockLedger::query()->where('reference_id', $order->id)->where('transaction_type', StockTransactionType::Dispatch)->count())->toBe(2);
});

it('detects a completed production batch missing its finished inward ledger', function () {
    $product = fgRecProduct('MISS-'.uniqid(), 0);
    $bom = Bom::query()->create([
        'product_id' => $product->id,
        'standard_batch_size' => 1,
        'output_quantity' => 1,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => '2026-09-07',
        'status' => BomStatus::Active,
        'wastage_percentage' => 0,
    ]);
    ProductionBatch::query()->create([
        'product_id' => $product->id,
        'bom_id' => $bom->id,
        'bom_version' => '1',
        'production_date' => '2026-09-07',
        'planned_quantity' => 8,
        'actual_output_quantity' => 8,
        'status' => ProductionBatchStatus::Completed,
        'completed_at' => now('Asia/Kolkata'),
    ]);

    $missing = app(FinishedProductInventoryAuditService::class)->detectMissingProductionInwards();

    expect(collect($missing)->pluck('product_id'))->toContain($product->id);
});

it('prints a concise read-only audit summary by default', function () {
    $this->artisan('inventory:audit-finished-product-stock')
        ->expectsOutputToContain('Total Products:')
        ->expectsOutputToContain('Missing Dispatch Outwards:')
        ->expectsOutputToContain('Missing Inwards/Production:')
        ->assertSuccessful();
});
