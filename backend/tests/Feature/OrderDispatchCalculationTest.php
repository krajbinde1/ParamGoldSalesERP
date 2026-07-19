<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Models\Dealer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Orders\OrderDispatchCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dispatchTestEmployee(): \App\Models\Employee
{
    static $counter = 9300000000;

    $counter++;

    return app(CreateEmployeeWithUserAccount::class)
        ->execute([
            'full_name' => 'Dispatch Test Employee '.$counter,
            'mobile' => (string) $counter,
            'email' => "dispatch.test.{$counter}@example.com",
            'department' => 'Sales',
            'designation' => 'Sales Executive',
            'joining_date' => '2026-07-01',
            'salary' => 20000,
            'base_location' => 'Pune',
            'daily_allowance' => 200,
            'travel_allowance_type' => 'actual_expense',
            'company_card_issued' => false,
            'monthly_travel_expense_limit' => 400,
            'aadhaar_number' => str_pad((string) $counter, 12, '5', STR_PAD_LEFT),
            'pan_number' => 'ABCDE'.str_pad((string) ($counter % 10000), 4, '0', STR_PAD_LEFT).'H',
            'bank_name' => 'Test Bank',
            'account_number' => str_pad((string) $counter, 12, '6', STR_PAD_LEFT),
            'ifsc_code' => 'TEST0123456',
            'status' => true,
        ])
        ->employee
        ->refresh();
}

function dispatchTestProduct(string $name, float $gst, float $price = 100): Product
{
    return Product::query()->create([
        'product_name' => $name,
        'category' => 'General',
        'uom' => 'Piece',
        'nos_per_case' => 1,
        'gst_percentage' => $gst,
        'dealer_price' => $price,
        'status' => true,
    ]);
}

function dispatchTestOrder(array $items): Order
{
    $dealer = Dealer::query()->create([
        'firm_name' => 'Dispatch Dealer',
        'owner_name' => 'Owner',
        'mobile' => '9876543210',
        'village' => 'Test',
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'status' => true,
    ]);

    $employee = dispatchTestEmployee();

    $order = Order::query()->create([
        'order_date' => now('Asia/Kolkata')->toDateString(),
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'status' => 'approved',
        'payment_type' => 'Credit',
        'subtotal' => 0,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 0,
    ]);

    foreach ($items as $item) {
        $order->items()->create([
            'product_id' => $item['product']->id,
            'quantity' => $item['quantity'],
            'unit' => $item['unit'] ?? 'PCS',
            'rate' => $item['rate'],
            'discount_percentage' => $item['discount_percentage'] ?? 0,
            'gst_percentage' => $item['gst_percentage'] ?? $item['product']->gst_percentage,
        ]);
    }

    $order->refresh()->recalculateTotals();

    return $order->fresh(['items.product']);
}

it('calculates dispatch totals after proportional transport deduction', function () {
    $product = dispatchTestProduct('Gold Item', 18, 100);
    $order = dispatchTestOrder([
        [
            'product' => $product,
            'quantity' => 100,
            'rate' => 100,
        ],
    ]);

    $calculation = app(OrderDispatchCalculationService::class)->calculate(
        $order,
        'company_transport',
        500,
    );

    expect($calculation['subtotal_before_transport'])->toBe(10000.0);
    expect($calculation['transport_amount'])->toBe(500.0);
    expect($calculation['taxable_amount_after_transport'])->toBe(9500.0);
    expect($calculation['total_gst'])->toBe(1710.0);
    expect($calculation['grand_total'])->toBe(11210.0);
});

it('distributes transport proportionally across mixed gst lines', function () {
    $productA = dispatchTestProduct('Item A', 18, 100);
    $productB = dispatchTestProduct('Item B', 5, 200);
    $order = dispatchTestOrder([
        ['product' => $productA, 'quantity' => 1, 'rate' => 6000],
        ['product' => $productB, 'quantity' => 1, 'rate' => 4000],
    ]);

    $calculation = app(OrderDispatchCalculationService::class)->calculate(
        $order,
        'outside_transport',
        1000,
    );

    expect($calculation['subtotal_before_transport'])->toBe(10000.0);
    expect($calculation['taxable_amount_after_transport'])->toBe(9000.0);

    $lineTotals = collect($calculation['items'])->sum('line_total');
    expect(round($lineTotals, 2))->toBe($calculation['grand_total']);
});

it('rejects transport amount above subtotal before transport', function () {
    $product = dispatchTestProduct('Reject Item', 18);
    $order = dispatchTestOrder([
        ['product' => $product, 'quantity' => 1, 'rate' => 1000],
    ]);

    app(OrderDispatchCalculationService::class)->calculate(
        $order,
        'company_transport',
        1500,
    );
})->throws(Illuminate\Validation\ValidationException::class);
