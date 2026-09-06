<?php

use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Models\Product;
use App\Models\StockLedger;
use App\Services\Inventory\FinishedProductOpeningStockCalculator;
use App\Services\Inventory\FinishedProductStockBalanceService;

function finishedBalanceProduct(array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'product_name' => 'FG Balance '.uniqid(),
        'uom' => 'Piece',
        'nos_per_case' => 20,
        'dealer_price' => 100,
        'status' => true,
        'manufacturing_enabled' => true,
        'current_finished_stock' => 0,
        'opening_finished_stock' => 0,
        'weighted_average_cost' => 0,
    ], $overrides));
}

function finishedBalanceLedger(Product $product, array $overrides = []): StockLedger
{
    return StockLedger::query()->create(array_merge([
        'transaction_date' => now('Asia/Kolkata')->toDateString(),
        'transaction_type' => StockTransactionType::OpeningStock,
        'item_type' => StockItemType::FinishedProduct,
        'product_id' => $product->id,
        'quantity_in' => 0,
        'quantity_out' => 0,
        'stock_before' => 0,
        'stock_after' => 0,
        'rate' => 0,
        'transaction_value' => 0,
        'inward_value' => 0,
        'outward_value' => 0,
        'remarks' => 'Test',
    ], $overrides));
}

it('sets current finished stock and wac from opening stock when there are no other transactions', function (): void {
    $product = finishedBalanceProduct(['opening_finished_stock' => 40]);

    finishedBalanceLedger($product, [
        'quantity_in' => 40,
        'stock_after' => 40,
        'rate' => 102.50,
        'transaction_value' => 4100,
        'inward_value' => 4100,
        'new_average_rate' => 102.50,
        'remarks' => 'Opening Stock',
    ]);

    $synced = app(FinishedProductStockBalanceService::class)->syncFromLedgers($product->fresh());

    expect((float) $synced->current_finished_stock)->toBe(40.0)
        ->and((float) $synced->weighted_average_cost)->toBe(102.5)
        ->and((float) $synced->current_stock_value)->toBe(4100.0)
        ->and(FinishedProductOpeningStockCalculator::casesFromQty(
            (float) $synced->current_finished_stock,
            (int) $synced->nos_per_case,
        ))->toBe(2.0);
});

it('blends manufacturing inward into weighted average cost and adds to current stock', function (): void {
    $product = finishedBalanceProduct();

    finishedBalanceLedger($product, [
        'quantity_in' => 40,
        'stock_after' => 40,
        'rate' => 102.50,
        'transaction_value' => 4100,
        'inward_value' => 4100,
    ]);

    finishedBalanceLedger($product, [
        'transaction_type' => StockTransactionType::ProductionOutput,
        'quantity_in' => 20,
        'stock_before' => 40,
        'stock_after' => 60,
        'rate' => 80,
        'transaction_value' => 1600,
        'inward_value' => 1600,
        'remarks' => 'Production',
    ]);

    $synced = app(FinishedProductStockBalanceService::class)->syncFromLedgers($product->fresh());

    expect((float) $synced->current_finished_stock)->toBe(60.0)
        ->and((float) $synced->weighted_average_cost)->toBe(95.0)
        ->and((float) $synced->current_stock_value)->toBe(5700.0);
});

it('reduces current finished stock on outward movements without creating extra ledgers', function (): void {
    $product = finishedBalanceProduct();

    finishedBalanceLedger($product, [
        'quantity_in' => 40,
        'stock_after' => 40,
        'rate' => 102.50,
        'transaction_value' => 4100,
        'inward_value' => 4100,
    ]);

    finishedBalanceLedger($product, [
        'transaction_type' => StockTransactionType::Dispatch,
        'quantity_out' => 20,
        'stock_before' => 40,
        'stock_after' => 20,
        'rate' => 102.50,
        'transaction_value' => 2050,
        'outward_value' => 2050,
        'remarks' => 'Dispatch',
    ]);

    $before = StockLedger::query()->where('product_id', $product->id)->count();
    $synced = app(FinishedProductStockBalanceService::class)->syncFromLedgers($product->fresh());

    expect((float) $synced->current_finished_stock)->toBe(20.0)
        ->and((float) $synced->weighted_average_cost)->toBe(102.5)
        ->and((float) $synced->current_stock_value)->toBe(2050.0)
        ->and(StockLedger::query()->where('product_id', $product->id)->count())->toBe($before);
});
