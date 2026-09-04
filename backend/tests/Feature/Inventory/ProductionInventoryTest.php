<?php

use App\Enums\BomItemType;
use App\Enums\BomStatus;
use App\Enums\ProductionBatchStatus;
use App\Enums\StockAdjustmentType;
use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Filament\Resources\Boms\Pages\ListBoms;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\RawMaterial;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\BatchReversalService;
use App\Services\Inventory\BOMCalculationService;
use App\Services\Inventory\FinishedProductPostingService;
use App\Services\Inventory\InventoryReportService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\ProductionCostingService;
use App\Services\Inventory\ProductionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function inventoryDirector(): User
{
    return User::query()->create([
        'name' => 'Director Test',
        'email' => 'director.inventory.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
}

function inventorySupervisor(): User
{
    return User::query()->create([
        'name' => 'Supervisor Test',
        'email' => 'supervisor.inventory.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::ProductionSupervisor->value,
        'job_role' => 'Production Supervisor',
    ]);
}

function inventoryEmployee(): User
{
    return User::query()->create([
        'name' => 'Employee Test',
        'email' => 'employee.inventory.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
        'job_role' => 'Sales Executive',
    ]);
}

/**
 * @return array{product: Product, bom: Bom, raw: RawMaterial, pack: PackagingMaterial}
 */
function seedManufacturingFixture(float $rawStock = 100, float $packStock = 100): array
{
    $raw = RawMaterial::query()->create([
        'material_name' => 'Gold Alloy',
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => $rawStock,
        'minimum_stock' => 5,
        'purchase_rate' => 100,
        'average_rate' => 100,
        'status' => true,
    ]);

    $pack = PackagingMaterial::query()->create([
        'packaging_name' => 'Carton Box',
        'category' => 'Boxes',
        'unit' => 'Nos',
        'opening_stock' => $packStock,
        'minimum_stock' => 5,
        'purchase_rate' => 10,
        'average_rate' => 10,
        'status' => true,
    ]);

    $product = Product::query()->create([
        'product_name' => 'Gold Coin 10g',
        'uom' => 'Nos',
        'dealer_price' => 500,
        'status' => true,
        'manufacturing_enabled' => true,
        'production_unit' => 'Nos',
        'standard_batch_size' => 10,
        'shelf_life_days' => 365,
    ]);

    $bom = Bom::query()->create([
        'product_id' => $product->id,
        'standard_batch_size' => 1,
        'output_quantity' => 1,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
        'wastage_percentage' => 0,
    ]);

    BomItem::query()->create([
        'bom_id' => $bom->id,
        'item_type' => BomItemType::RawMaterial,
        'raw_material_id' => $raw->id,
        'required_quantity' => 1,
        'unit' => 'Kg',
        'wastage_percentage' => 0,
        'calculated_quantity' => 1,
        'is_optional' => false,
        'sort_order' => 1,
    ]);

    BomItem::query()->create([
        'bom_id' => $bom->id,
        'item_type' => BomItemType::PackagingMaterial,
        'packaging_material_id' => $pack->id,
        'required_quantity' => 1,
        'unit' => 'Nos',
        'wastage_percentage' => 0,
        'calculated_quantity' => 1,
        'is_optional' => false,
        'sort_order' => 2,
    ]);

    return compact('product', 'bom', 'raw', 'pack');
}

it('keeps calculateItemQuantity available for legacy callers', function () {
    $service = new BOMCalculationService;

    expect($service->calculateItemQuantity(10, 10))->toBe(11.0)
        ->and($service->calculateItemQuantity(5, 0))->toBe(5.0);
});

it('syncs calculated quantity equal to required quantity without wastage', function () {
    $fixture = seedManufacturingFixture();
    $bom = $fixture['bom']->load('items');
    $item = $bom->items->first();
    $item->required_quantity = 10;
    $item->wastage_percentage = 25;
    $item->calculated_quantity = 99;
    $item->saveQuietly();

    app(BOMCalculationService::class)->syncCalculatedQuantities($bom->fresh('items'));

    $item->refresh();
    expect((float) $item->calculated_quantity)->toBe(10.0)
        ->and((float) $item->wastage_percentage)->toBe(0.0);
});

it('proportionately explodes bom requirements for planned quantity', function () {
    $fixture = seedManufacturingFixture();
    $rows = app(BOMCalculationService::class)->explodeRequirements($fixture['bom']->load('items.rawMaterial', 'items.packagingMaterial'), 20);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['required_quantity'])->toBe(20.0)
        ->and($rows[1]['required_quantity'])->toBe(20.0);
});

it('scales materials using batch_quantity formula for quantity', function () {
    $fixture = seedManufacturingFixture();
    $fixture['bom']->update([
        'batch_quantity' => 100,
        'batch_unit' => 'Nos',
    ]);
    $fixture['bom']->items()->where('item_type', BomItemType::RawMaterial)->update([
        'required_quantity' => 500,
        'calculated_quantity' => 500,
        'unit' => 'Kg',
    ]);
    $fixture['bom']->items()->where('item_type', BomItemType::PackagingMaterial)->update([
        'required_quantity' => 100,
        'calculated_quantity' => 100,
        'unit' => 'Nos',
    ]);

    $rows = app(BOMCalculationService::class)->explodeRequirements(
        $fixture['bom']->fresh()->load('items.rawMaterial', 'items.packagingMaterial'),
        250,
    );

    $rawRow = collect($rows)->firstWhere('item_type', BomItemType::RawMaterial->value);
    $packRow = collect($rows)->firstWhere('item_type', BomItemType::PackagingMaterial->value);

    // (500 / 100) * 250 = 1250 Kg; (100 / 100) * 250 = 250 Nos
    expect($rawRow['required_quantity'])->toBe(1250.0)
        ->and($packRow['required_quantity'])->toBe(250.0);
});

it('rejects production when mandatory stock is insufficient', function () {
    $fixture = seedManufacturingFixture(rawStock: 0.5, packStock: 100);
    $supervisor = inventorySupervisor();

    expect(fn () => app(ProductionService::class)->completeProduction([
        'product_id' => $fixture['product']->id,
        'planned_quantity' => 10,
        'actual_output_quantity' => 10,
        'production_date' => now()->toDateString(),
        'labour_cost' => 50,
    ], $supervisor))->toThrow(ValidationException::class);
});

it('deducts raw and packaging materials and increases finished stock on production', function () {
    $fixture = seedManufacturingFixture();
    $supervisor = inventorySupervisor();

    $batch = app(ProductionService::class)->completeProduction([
        'product_id' => $fixture['product']->id,
        'planned_quantity' => 10,
        'actual_output_quantity' => 10,
        'production_date' => now()->toDateString(),
        'labour_cost' => 100,
        'electricity_cost' => 50,
        'machine_cost' => 25,
        'processing_cost' => 15,
        'transport_cost' => 10,
        'other_manufacturing_cost' => 0,
    ], $supervisor);

    expect($batch->status)->toBe(ProductionBatchStatus::Completed)
        ->and((float) $fixture['raw']->fresh()->current_stock)->toBe(90.0)
        ->and((float) $fixture['pack']->fresh()->current_stock)->toBe(90.0)
        ->and((float) $fixture['product']->fresh()->current_finished_stock)->toBe(10.0)
        ->and($batch->consumptions)->toHaveCount(2)
        ->and((float) $batch->cost_per_pack)->toBeGreaterThan(0);
});

it('calculates production costing snapshot correctly', function () {
    $costing = app(ProductionCostingService::class)->calculate([
        ['item_type' => BomItemType::RawMaterial->value, 'consumption_value' => 100],
        ['item_type' => BomItemType::PackagingMaterial->value, 'consumption_value' => 20],
    ], [
        'labour_cost' => 30,
        'transport_cost' => 10,
        'other_manufacturing_cost' => 10,
    ], 10);

    expect($costing['total_material_cost'])->toBe(100.0)
        ->and($costing['total_packaging_cost'])->toBe(20.0)
        ->and($costing['total_conversion_cost'])->toBe(50.0)
        ->and($costing['total_batch_cost'])->toBe(170.0)
        ->and($costing['cost_per_unit'])->toBe(17.0);
});

it('ignores removed electricity/machine/processing expense keys in costing formula', function () {
    $costing = app(ProductionCostingService::class)->calculate([
        ['item_type' => BomItemType::RawMaterial->value, 'consumption_value' => 100],
    ], [
        'labour_cost' => 30,
        'electricity_cost' => 999,
        'machine_cost' => 999,
        'processing_cost' => 999,
        'transport_cost' => 10,
        'other_manufacturing_cost' => 10,
    ], 10);

    expect($costing['total_conversion_cost'])->toBe(50.0)
        ->and($costing['total_batch_cost'])->toBe(150.0);
});

it('keeps production costing snapshots immutable when material rates change later', function () {
    $fixture = seedManufacturingFixture();
    $supervisor = inventorySupervisor();

    $batch = app(ProductionService::class)->completeProduction([
        'product_id' => $fixture['product']->id,
        'planned_quantity' => 10,
        'actual_output_quantity' => 10,
        'production_date' => now()->toDateString(),
        'labour_cost' => 20,
    ], $supervisor);

    $costSnapshot = (float) $batch->cost_per_unit;
    $materialCostSnapshot = (float) $batch->total_material_cost;
    $consumptionRate = (float) $batch->consumptions()->where('raw_material_id', $fixture['raw']->id)->value('rate');

    $fixture['raw']->update(['average_rate' => 999, 'purchase_rate' => 999]);

    expect((float) $batch->fresh()->cost_per_unit)->toBe($costSnapshot)
        ->and((float) $batch->fresh()->total_material_cost)->toBe($materialCostSnapshot)
        ->and($consumptionRate)->toBe(100.0)
        ->and((float) $fixture['raw']->fresh()->current_stock)->toBe(90.0);
});

it('rolls back production stock changes when an exception is thrown inside the transaction', function () {
    $fixture = seedManufacturingFixture();
    $supervisor = inventorySupervisor();
    $rawBefore = (float) $fixture['raw']->current_stock;
    $packBefore = (float) $fixture['pack']->current_stock;
    $finishedBefore = (float) $fixture['product']->current_finished_stock;

    try {
        DB::transaction(function () use ($fixture, $supervisor): void {
            app(ProductionService::class)->completeProduction([
                'product_id' => $fixture['product']->id,
                'planned_quantity' => 10,
                'actual_output_quantity' => 10,
                'production_date' => now()->toDateString(),
            ], $supervisor);

            throw new RuntimeException('Forced failure after posting');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect((float) $fixture['raw']->fresh()->current_stock)->toBe($rawBefore)
        ->and((float) $fixture['pack']->fresh()->current_stock)->toBe($packBefore)
        ->and((float) $fixture['product']->fresh()->current_finished_stock)->toBe($finishedBefore)
        ->and(ProductionBatch::query()->count())->toBe(0);
});

it('prevents negative stock on production and adjustment', function () {
    $fixture = seedManufacturingFixture(rawStock: 2, packStock: 2);
    $director = inventoryDirector();

    expect(fn () => app(ProductionService::class)->completeProduction([
        'product_id' => $fixture['product']->id,
        'planned_quantity' => 10,
        'actual_output_quantity' => 10,
        'production_date' => now()->toDateString(),
    ], inventorySupervisor()))->toThrow(ValidationException::class);

    expect(fn () => app(InventoryService::class)->adjustStock([
        'adjustment_date' => now()->toDateString(),
        'adjustment_type' => StockAdjustmentType::StockDecrease->value,
        'item_type' => StockItemType::RawMaterial->value,
        'raw_material_id' => $fixture['raw']->id,
        'adjusted_quantity' => 50,
        'reason' => 'Over decrease',
    ], $director))->toThrow(ValidationException::class);

    expect((float) $fixture['raw']->fresh()->current_stock)->toBe(2.0);
});

it('reverses a completed batch and restores stock', function () {
    $fixture = seedManufacturingFixture();
    $supervisor = inventorySupervisor();
    $director = inventoryDirector();

    $batch = app(ProductionService::class)->completeProduction([
        'product_id' => $fixture['product']->id,
        'planned_quantity' => 10,
        'actual_output_quantity' => 10,
        'production_date' => now()->toDateString(),
        'labour_cost' => 10,
    ], $supervisor);

    $reversed = app(BatchReversalService::class)->reverse($batch, 'Quality issue', $director);

    expect($reversed->status)->toBe(ProductionBatchStatus::Reversed)
        ->and((float) $fixture['raw']->fresh()->current_stock)->toBe(100.0)
        ->and((float) $fixture['pack']->fresh()->current_stock)->toBe(100.0)
        ->and((float) $fixture['product']->fresh()->current_finished_stock)->toBe(0.0)
        ->and($reversed->reversal_reason)->toBe('Quality issue')
        ->and($reversed->reversed_by)->toBe($director->id);
});

it('does not allow supervisor to reverse or adjust stock', function () {
    $fixture = seedManufacturingFixture();
    $supervisor = inventorySupervisor();

    $batch = app(ProductionService::class)->completeProduction([
        'product_id' => $fixture['product']->id,
        'planned_quantity' => 5,
        'actual_output_quantity' => 5,
        'production_date' => now()->toDateString(),
    ], $supervisor);

    expect(fn () => app(BatchReversalService::class)->reverse($batch, 'Nope', $supervisor))
        ->toThrow(ValidationException::class);

    expect($supervisor->canAdjustStock())->toBeFalse()
        ->and($supervisor->canManageBom())->toBeFalse()
        ->and($supervisor->canPostProduction())->toBeTrue()
        ->and(inventoryEmployee()->canAccessInventoryModule())->toBeFalse()
        ->and(inventoryDirector()->canReverseProductionBatch())->toBeTrue();
});

it('prevents duplicate production posting with the same posting token', function () {
    $fixture = seedManufacturingFixture();
    $supervisor = inventorySupervisor();
    $token = 'token-'.uniqid();

    $first = app(ProductionService::class)->completeProduction([
        'product_id' => $fixture['product']->id,
        'planned_quantity' => 5,
        'actual_output_quantity' => 5,
        'production_date' => now()->toDateString(),
        'posting_token' => $token,
    ], $supervisor);

    expect(fn () => app(ProductionService::class)->completeProduction([
        'product_id' => $fixture['product']->id,
        'planned_quantity' => 5,
        'actual_output_quantity' => 5,
        'production_date' => now()->toDateString(),
        'posting_token' => $token,
    ], $supervisor))->toThrow(ValidationException::class);

    expect(ProductionBatch::query()->count())->toBe(1)
        ->and((float) $fixture['product']->fresh()->current_finished_stock)->toBe(5.0)
        ->and($first->isFinishedProductStockPosted())->toBeTrue();
});

it('posts finished product stock ledger and weighted average cost on completion', function () {
    $fixture = seedManufacturingFixture();
    $fixture['product']->update([
        'current_finished_stock' => 10,
        'weighted_average_cost' => 20,
    ]);
    $supervisor = inventorySupervisor();

    $batch = app(ProductionService::class)->completeProduction([
        'product_id' => $fixture['product']->id,
        'planned_quantity' => 10,
        'actual_output_quantity' => 10,
        'production_date' => now()->toDateString(),
        'labour_cost' => 100,
        'transport_cost' => 50,
        'other_manufacturing_cost' => 50,
    ], $supervisor);

    // Material 10*100 + Pack 10*10 + labour/transport/other 200 = 1000+100+200 = 1300
    expect((float) $batch->total_batch_cost)->toBe(1300.0)
        ->and((float) $batch->cost_per_unit)->toBe(130.0)
        ->and($batch->isFinishedProductStockPosted())->toBeTrue()
        ->and((float) $batch->finished_stock_before)->toBe(10.0)
        ->and((float) $batch->finished_stock_after)->toBe(20.0)
        ->and((float) $batch->finished_stock_value_after)->toBe(1500.0);

    $product = $fixture['product']->fresh();
    // Old value 10*20=200 + batch 1300 = 1500; avg = 1500/20 = 75
    expect((float) $product->current_finished_stock)->toBe(20.0)
        ->and((float) $product->weighted_average_cost)->toBe(75.0)
        ->and((float) $product->average_production_cost)->toBe(75.0)
        ->and((float) $product->current_stock_value)->toBe(1500.0);

    $fgLedger = StockLedger::query()
        ->where('item_type', StockItemType::FinishedProduct)
        ->where('transaction_type', StockTransactionType::ProductionOutput)
        ->where('reference_id', $batch->id)
        ->first();

    expect($fgLedger)->not->toBeNull()
        ->and((float) $fgLedger->quantity_in)->toBe(10.0)
        ->and((float) $fgLedger->quantity_out)->toBe(0.0)
        ->and((float) $fgLedger->transaction_value)->toBe(1300.0)
        ->and((float) $fgLedger->inward_value)->toBe(1300.0)
        ->and($fgLedger->remarks)->toBe('Production Batch '.$batch->batch_number)
        ->and(StockTransactionType::ProductionOutput->label())->toBe('Finished Product Production');
});

it('shows finished products and finished product value on inventory stock report', function () {
    $fixture = seedManufacturingFixture();
    $supervisor = inventorySupervisor();

    app(ProductionService::class)->completeProduction([
        'product_id' => $fixture['product']->id,
        'planned_quantity' => 10,
        'actual_output_quantity' => 10,
        'production_date' => now()->toDateString(),
        'labour_cost' => 0,
    ], $supervisor);

    $report = app(InventoryReportService::class)->build([
        'inventory_type' => InventoryReportService::TYPE_FINISHED_PRODUCT,
    ]);

    $labels = collect($report->summaryCards)->pluck('label')->all();
    $rows = iterator_to_array($report->exportRows());

    expect($labels)->toContain('Finished Product Value')
        ->and($rows)->not->toBeEmpty()
        ->and(collect($rows)->contains(fn (array $row): bool => str_contains((string) $row[1], 'Gold Coin')))->toBeTrue();
});

it('backfills finished product stock for completed batches without re-consuming materials', function () {
    $fixture = seedManufacturingFixture();
    $supervisor = inventorySupervisor();

    $batch = app(ProductionService::class)->completeProduction([
        'product_id' => $fixture['product']->id,
        'planned_quantity' => 10,
        'actual_output_quantity' => 10,
        'production_date' => now()->toDateString(),
        'labour_cost' => 20,
    ], $supervisor);

    $rawAfterPost = (float) $fixture['raw']->fresh()->current_stock;
    $packAfterPost = (float) $fixture['pack']->fresh()->current_stock;

    // Simulate legacy completed batch: FG ledger + product stock removed, flags cleared.
    StockLedger::query()
        ->where('item_type', StockItemType::FinishedProduct)
        ->where('reference_id', $batch->id)
        ->delete();

    Product::query()->whereKey($fixture['product']->id)->update([
        'current_finished_stock' => 0,
        'weighted_average_cost' => 0,
        'latest_production_cost' => 0,
    ]);
    ProductionBatch::query()->whereKey($batch->id)->update([
        'finished_product_posted_at' => null,
        'finished_stock_before' => null,
        'finished_stock_after' => null,
        'finished_stock_value_after' => null,
        'finished_product_ledger_id' => null,
    ]);

    expect((float) $fixture['product']->fresh()->current_finished_stock)->toBe(0.0);

    $result = app(FinishedProductPostingService::class)
        ->backfillMissing($batch->fresh(), $supervisor);

    expect($result['status'])->toBe('posted')
        ->and((float) $fixture['product']->fresh()->current_finished_stock)->toBe(10.0)
        ->and((float) $fixture['raw']->fresh()->current_stock)->toBe($rawAfterPost)
        ->and((float) $fixture['pack']->fresh()->current_stock)->toBe($packAfterPost)
        ->and($batch->fresh()->isFinishedProductStockPosted())->toBeTrue();

    $again = app(FinishedProductPostingService::class)
        ->backfillMissing($batch->fresh(), $supervisor);

    expect($again['status'])->toBe('already_posted')
        ->and((float) $fixture['product']->fresh()->current_finished_stock)->toBe(10.0);
});

it('rejects production without active bom and unrealistic output', function () {
    $product = Product::query()->create([
        'product_name' => 'No BOM Product',
        'uom' => 'Nos',
        'dealer_price' => 10,
        'status' => true,
        'manufacturing_enabled' => true,
    ]);

    expect(fn () => app(ProductionService::class)->completeProduction([
        'product_id' => $product->id,
        'planned_quantity' => 10,
        'actual_output_quantity' => 10,
        'production_date' => now()->toDateString(),
    ], inventorySupervisor()))->toThrow(ValidationException::class);

    $fixture = seedManufacturingFixture();

    expect(fn () => app(ProductionService::class)->completeProduction([
        'product_id' => $fixture['product']->id,
        'planned_quantity' => 10,
        'actual_output_quantity' => 50,
        'production_date' => now()->toDateString(),
    ], inventorySupervisor()))->toThrow(ValidationException::class);
});

it('ensures only one active bom per product', function () {
    $fixture = seedManufacturingFixture();

    $bom2 = Bom::query()->create([
        'product_id' => $fixture['product']->id,
        'standard_batch_size' => 10,
        'output_quantity' => 10,
        'batch_quantity' => 10,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);

    BomItem::query()->create([
        'bom_id' => $bom2->id,
        'item_type' => BomItemType::RawMaterial,
        'raw_material_id' => $fixture['raw']->id,
        'required_quantity' => 10,
        'unit' => 'Kg',
        'wastage_percentage' => 0,
        'calculated_quantity' => 10,
        'is_optional' => false,
        'sort_order' => 1,
    ]);

    app(BOMCalculationService::class)->ensureSingleActiveBom($bom2);

    expect($fixture['bom']->fresh()->status)->toBe(BomStatus::Inactive)
        ->and($bom2->fresh()->status)->toBe(BomStatus::Active);
});

it('allows separate active boms for different packing products', function () {
    $fixture = seedManufacturingFixture();

    $product2 = Product::query()->create([
        'product_name' => 'Gold Coin 50g',
        'uom' => 'Nos',
        'dealer_price' => 500,
        'status' => true,
        'manufacturing_enabled' => true,
        'production_unit' => 'Nos',
    ]);

    $bom2 = Bom::query()->create([
        'product_id' => $product2->id,
        'standard_batch_size' => 10,
        'output_quantity' => 10,
        'batch_quantity' => 10,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);

    BomItem::query()->create([
        'bom_id' => $bom2->id,
        'item_type' => BomItemType::RawMaterial,
        'raw_material_id' => $fixture['raw']->id,
        'required_quantity' => 10,
        'unit' => 'Kg',
        'wastage_percentage' => 0,
        'calculated_quantity' => 10,
        'is_optional' => false,
        'sort_order' => 1,
    ]);

    app(BOMCalculationService::class)->ensureSingleActiveBom($bom2);

    expect($fixture['bom']->fresh()->status)->toBe(BomStatus::Active)
        ->and($bom2->fresh()->status)->toBe(BomStatus::Active);
});

it('rolls back database transaction when an exception occurs during posting', function () {
    $fixture = seedManufacturingFixture();
    $supervisor = inventorySupervisor();

    $batch = app(ProductionService::class)->completeProduction([
        'product_id' => $fixture['product']->id,
        'planned_quantity' => 10,
        'actual_output_quantity' => 10,
        'production_date' => now()->toDateString(),
    ], $supervisor);

    $fixture['product']->update(['current_finished_stock' => 1]);

    expect(fn () => app(BatchReversalService::class)->reverse($batch, 'Cannot reverse', inventoryDirector()))
        ->toThrow(ValidationException::class);

    expect($batch->fresh()->status)->toBe(ProductionBatchStatus::Completed)
        ->and((float) $fixture['raw']->fresh()->current_stock)->toBe(90.0);
});

it('allows activating quantity-wise bom when raw material qty differs from formula nos', function () {
    $fixture = seedManufacturingFixture();
    $fixture['bom']->update(['batch_quantity' => 100, 'batch_unit' => 'Nos']);
    $fixture['bom']->items()->where('item_type', BomItemType::RawMaterial)->update([
        'required_quantity' => 500,
        'calculated_quantity' => 500,
        'unit' => 'Kg',
    ]);

    $bom = $fixture['bom']->fresh(['items']);
    $bom->status = BomStatus::Active;

    app(BOMCalculationService::class)->assertBomCanBeActivated($bom);

    $summary = app(BOMCalculationService::class)->summarizeBom($bom, $bom->items);
    expect($summary['formula_quantity'])->toBe(100.0)
        ->and($summary['batch_unit'])->toBe('Nos')
        ->and($summary['raw_material_items'])->toBe(1)
        ->and($summary['packaging_material_items'])->toBe(1)
        ->and($summary['total_items'])->toBe(2);
});

it('rejects activating bom when batch unit is not Nos', function () {
    $fixture = seedManufacturingFixture();
    $fixture['bom']->update(['batch_quantity' => 100, 'batch_unit' => 'Kg']);

    $bom = $fixture['bom']->fresh(['items']);
    $bom->status = BomStatus::Active;

    expect(fn () => app(BOMCalculationService::class)->assertBomCanBeActivated($bom))
        ->toThrow(ValidationException::class);

    try {
        app(BOMCalculationService::class)->assertBomCanBeActivated($bom);
    } catch (ValidationException $e) {
        expect($e->errors()['batch_unit'][0] ?? '')->toContain('Nos');
    }
});

it('rejects activating bom without items', function () {
    $fixture = seedManufacturingFixture();
    $fixture['bom']->items()->delete();

    $bom = $fixture['bom']->fresh(['items']);
    $bom->status = BomStatus::Active;

    expect(fn () => app(BOMCalculationService::class)->assertBomCanBeActivated($bom))
        ->toThrow(ValidationException::class);
});

it('summarizes bom costs without combining incompatible units', function () {
    $fixture = seedManufacturingFixture();
    $fixture['bom']->update(['batch_quantity' => 100, 'batch_unit' => 'Nos']);
    $fixture['bom']->items()->where('item_type', BomItemType::RawMaterial)->update([
        'required_quantity' => 500,
        'unit' => 'Kg',
    ]);
    $fixture['bom']->items()->where('item_type', BomItemType::PackagingMaterial)->update([
        'required_quantity' => 100,
        'unit' => 'Nos',
    ]);

    $summary = app(BOMCalculationService::class)->summarizeBom(
        $fixture['bom']->fresh(),
        $fixture['bom']->fresh('items')->items,
    );

    // Raw: 500 * 100 = 50000; Pack: 100 * 10 = 1000; Total 51000; Per unit 510
    expect($summary['estimated_raw_material_cost'])->toBe(50000.0)
        ->and($summary['estimated_packaging_cost'])->toBe(1000.0)
        ->and($summary['estimated_total_bom_cost'])->toBe(51000.0)
        ->and($summary['estimated_cost_per_finished_unit'])->toBe(510.0)
        ->and($summary)->not->toHaveKey('total_raw_material_quantity')
        ->and($summary)->not->toHaveKey('completion_percentage');
});

it('shows estimated cost per unit on the bom list from the current formula and material rates', function () {
    $fixture = seedManufacturingFixture();
    $fixture['bom']->update(['batch_quantity' => 1, 'batch_unit' => 'Nos']);

    Livewire::actingAs(inventoryDirector())
        ->test(ListBoms::class)
        ->assertSuccessful()
        ->assertSee('Estimated Cost / Unit')
        ->assertSee('Formula For Quantity')
        ->assertSee('₹110.00');

    $fixture['raw']->update(['average_rate' => 200]);

    Livewire::actingAs(inventoryDirector())
        ->test(ListBoms::class)
        ->assertSuccessful()
        ->assertSee('₹210.00');
});

it('hides estimated bom cost on the list from users who cannot view production costs', function () {
    seedManufacturingFixture();

    Livewire::actingAs(inventorySupervisor())
        ->test(ListBoms::class)
        ->assertSuccessful()
        ->assertDontSee('Estimated Cost / Unit');
});

it('allows saving inactive bom with non-Nos batch unit', function () {
    $fixture = seedManufacturingFixture();
    $fixture['bom']->update(['batch_quantity' => 100, 'batch_unit' => 'Kg', 'status' => BomStatus::Inactive]);

    $bom = $fixture['bom']->fresh(['items']);

    $summary = app(BOMCalculationService::class)->assertBomFormulaForSave($bom, $bom->items, BomStatus::Inactive);
    expect($summary['formula_quantity'])->toBe(100.0);

    expect(fn () => app(BOMCalculationService::class)->assertBomFormulaForSave($bom, $bom->items, BomStatus::Active))
        ->toThrow(ValidationException::class);
});

it('allows production preview when raw material qty differs from formula nos', function () {
    $fixture = seedManufacturingFixture(rawStock: 1000, packStock: 1000);
    $fixture['bom']->update(['batch_quantity' => 100, 'batch_unit' => 'Nos']);
    $fixture['bom']->items()->where('item_type', BomItemType::RawMaterial)->update([
        'required_quantity' => 20,
        'unit' => 'Kg',
    ]);

    $preview = app(ProductionService::class)->preview([
        'product_id' => $fixture['product']->id,
        'planned_quantity' => 10,
        'actual_output_quantity' => 10,
    ]);

    expect($preview)->toBeArray();
});
