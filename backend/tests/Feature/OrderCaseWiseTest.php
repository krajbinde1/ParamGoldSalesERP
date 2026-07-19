<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Orders\OrderLineCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function caseWiseProduct(array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'product_name' => 'ParamGold 1 Litre',
        'category' => 'General',
        'uom' => 'Litre',
        'nos_per_case' => 20,
        'gst_percentage' => 18,
        'dealer_price' => 150,
        'status' => true,
    ], $overrides));
}

it('calculates case-wise order lines with discount and gst', function () {
    $product = caseWiseProduct();
    $calculator = app(OrderLineCalculationService::class);

    $line = $calculator->calculateForProduct(
        product: $product,
        caseQuantity: 5,
        ratePerNo: 150,
        requestedDiscountPercentage: 0,
        requestedGstPercentage: 18,
    );

    expect($line['case_quantity'])->toBe(5)
        ->and($line['nos_per_case'])->toBe(20)
        ->and($line['total_quantity_nos'])->toBe(100)
        ->and($line['base_amount'])->toBe(15000.0)
        ->and($line['gst_amount'])->toBe(2700.0)
        ->and($line['final_amount'])->toBe(17700.0);
});

it('disables discount when rate per no differs from dealer price', function () {
    $product = caseWiseProduct();
    $calculator = app(OrderLineCalculationService::class);

    $line = $calculator->calculateForProduct(
        product: $product,
        caseQuantity: 2,
        ratePerNo: 140,
        requestedDiscountPercentage: 10,
        requestedGstPercentage: 18,
    );

    expect($line['discount_percentage'])->toBe(0.0)
        ->and($line['discount_amount'])->toBe(0.0)
        ->and($line['base_amount'])->toBe(5600.0);
});

it('stores nos per case snapshot on order items', function () {
    $product = caseWiseProduct(['nos_per_case' => 20]);
    $calculator = app(OrderLineCalculationService::class);
    $line = $calculator->calculateForProduct($product, 3, 150, 0, 18);

    $order = Order::query()->create([
        'order_no' => 'PG-TEST-0001',
        'order_date' => now()->toDateString(),
        'dealer_id' => null,
        'sales_employee_id' => null,
        'status' => 'pending_approval',
        'payment_type' => 'Credit',
        'subtotal' => 0,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 0,
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'case_quantity' => $line['case_quantity'],
        'nos_per_case' => $line['nos_per_case'],
        'total_quantity_nos' => $line['total_quantity_nos'],
        'quantity' => $line['quantity'],
        'unit' => $line['unit'],
        'rate_per_no' => $line['rate_per_no'],
        'rate' => $line['rate'],
        'discount_percentage' => $line['discount_percentage'],
        'discount_amount' => $line['discount_amount'],
        'gst_percentage' => $line['gst_percentage'],
        'base_amount' => $line['base_amount'],
        'taxable_amount' => $line['taxable_amount'],
        'gst_amount' => $line['gst_amount'],
        'final_amount' => $line['final_amount'],
        'line_total' => $line['line_total'],
    ]);

    $product->update(['nos_per_case' => 30]);

    $item = OrderItem::query()->first();

    expect($item->nos_per_case)->toBe(20)
        ->and($item->total_quantity_nos)->toBe(60);
});

it('preserves legacy order item totals through stored amount resolver', function () {
    $item = (object) [
        'quantity' => 10,
        'rate' => 100,
        'discount_percentage' => 0,
        'gst_percentage' => 18,
        'line_total' => 1180,
        'base_amount' => null,
    ];

    $amounts = app(OrderLineCalculationService::class)->resolveStoredAmounts($item);

    expect($amounts['base_amount'])->toBe(1000.0)
        ->and($amounts['final_amount'])->toBe(1180.0);
});

it('requires nos per case on product bulk import', function () {
    $csv = implode("\n", [
        productImportHeaderLine(),
        productImportDataLine(['nos_per_case' => '']),
    ]);
    $path = storage_path('framework/testing/product-import-no-nos.csv');
    \Illuminate\Support\Facades\File::put($path, $csv);

    $result = app(\App\Services\Products\ProductBulkImportService::class)->import($path);

    expect($result->failed())->toBe(1)
        ->and($result->errors[0]->reason)->toContain('Missing mandatory field: nos_per_case');
});
