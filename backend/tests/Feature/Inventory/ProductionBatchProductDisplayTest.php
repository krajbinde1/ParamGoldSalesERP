<?php

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\ProductionBatchStatus;
use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Filament\Resources\ProductionBatches\Pages\ListProductionBatches;
use App\Http\Resources\Production\ProductionBatchPresenter;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\ProductionBatchProductBackfillService;
use App\Services\Inventory\ProductionService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function pbProductDisplayDirector(): User
{
    return User::query()->create([
        'name' => 'PB Display Director',
        'email' => 'pb.display.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
}

function pbProductDisplayRaw(float $stock = 100): RawMaterial
{
    return RawMaterial::query()->create([
        'material_name' => 'Display Mix '.uniqid(),
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => $stock,
        'current_stock' => $stock,
        'minimum_stock' => 1,
        'purchase_rate' => 10,
        'average_rate' => 10,
        'status' => true,
    ]);
}

/**
 * @return array{product: Product, bom: Bom, raw: RawMaterial}
 */
function pbFinishedFixture(): array
{
    $raw = pbProductDisplayRaw();
    $product = Product::query()->create([
        'product_name' => 'Zinc Sulphate 10KG',
        'uom' => 'Nos',
        'dealer_price' => 100,
        'status' => true,
        'manufacturing_enabled' => true,
        'production_unit' => 'Nos',
        'standard_batch_size' => 1,
    ]);
    $bom = Bom::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
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
        'sort_order' => 1,
    ]);

    return ['product' => $product, 'bom' => $bom, 'raw' => $raw];
}

/**
 * @return array{semi: SemiFinishedMaterial, bom: Bom, raw: RawMaterial}
 */
function pbBulkFixture(): array
{
    $raw = pbProductDisplayRaw();
    $semi = SemiFinishedMaterial::query()->create([
        'material_name' => 'Zinc Sulphate Bulk',
        'unit' => 'Kg',
        'minimum_stock' => 0,
        'status' => true,
        'current_stock' => 0,
    ]);
    $bom = Bom::query()->create([
        'output_type' => BomOutputType::SemiFinished,
        'semi_finished_id' => $semi->id,
        'product_id' => null,
        'standard_batch_size' => 1,
        'output_quantity' => 1,
        'batch_quantity' => 1,
        'batch_unit' => 'Kg',
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
        'sort_order' => 1,
    ]);

    return ['semi' => $semi, 'bom' => $bom, 'raw' => $raw];
}

it('lists finished and bulk batches as Product Code — Product Name', function () {
    $director = pbProductDisplayDirector();
    $fg = pbFinishedFixture();
    $bulk = pbBulkFixture();

    $fgBatch = app(ProductionService::class)->completeProduction([
        'product_id' => $fg['product']->id,
        'planned_quantity' => 2,
        'actual_output_quantity' => 2,
        'production_date' => now()->toDateString(),
        'posting_token' => 'pb-display-fg-'.uniqid(),
    ], $director);

    $sfgBatch = app(ProductionService::class)->completeProduction([
        'output_type' => BomOutputType::SemiFinished->value,
        'semi_finished_id' => $bulk['semi']->id,
        'planned_quantity' => 5,
        'actual_output_quantity' => 5,
        'production_date' => now()->toDateString(),
        'posting_token' => 'pb-display-sfg-'.uniqid(),
    ], $director);

    $fgLabel = $fg['product']->fresh()->displayLabel();
    $sfgLabel = ProductionBatch::semiFinishedLabel($bulk['semi']->fresh());

    expect($fgBatch->product_id)->toBe($fg['product']->id)
        ->and($sfgBatch->product_id)->toBeNull()
        ->and($sfgBatch->semi_finished_id)->toBe($bulk['semi']->id)
        ->and($fgBatch->outputDisplayLabel())->toBe($fgLabel)
        ->and($sfgBatch->outputDisplayLabel())->toBe($sfgLabel)
        ->and($fgLabel)->toContain(' — ')
        ->and($sfgLabel)->toContain(' — ');

    Livewire::actingAs($director)
        ->test(ListProductionBatches::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$fgBatch, $sfgBatch])
        ->assertSee($fgLabel)
        ->assertSee($sfgLabel);

    $presented = ProductionBatchPresenter::summary($sfgBatch->fresh(['product', 'semiFinished']), $director);
    expect($presented['product_label'])->toBe($sfgLabel);
});

it('still shows Code — Name after the finished product is soft-deleted', function () {
    $director = pbProductDisplayDirector();
    $fg = pbFinishedFixture();

    $batch = ProductionBatch::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => $fg['product']->id,
        'bom_id' => $fg['bom']->id,
        'bom_version' => '1',
        'production_date' => now()->toDateString(),
        'planned_quantity' => 1,
        'actual_output_quantity' => 1,
        'status' => ProductionBatchStatus::Completed,
    ]);

    $expected = $fg['product']->displayLabel();
    Product::withoutEvents(fn () => $fg['product']->delete());

    $batch = $batch->fresh();
    $batch->unsetRelation('product');

    expect($batch->outputDisplayLabel())->toBe($expected);

    Livewire::actingAs($director)
        ->test(ListProductionBatches::class)
        ->assertSuccessful()
        ->assertSee($expected);
});

it('rejects creating a finished-product batch without a product', function () {
    $fg = pbFinishedFixture();

    expect(fn () => ProductionBatch::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => null,
        'bom_id' => $fg['bom']->id,
        'bom_version' => '1',
        'production_date' => now()->toDateString(),
        'planned_quantity' => 1,
        'actual_output_quantity' => 1,
        'status' => ProductionBatchStatus::Draft,
    ]))->toThrow(ValidationException::class);

    $director = pbProductDisplayDirector();

    expect(fn () => app(ProductionService::class)->completeProduction([
        'planned_quantity' => 1,
        'actual_output_quantity' => 1,
        'production_date' => now()->toDateString(),
        'posting_token' => 'pb-display-missing-'.uniqid(),
    ], $director))->toThrow(ValidationException::class);
});

it('backfills missing finished product_id from the BOM when identity is unique', function () {
    $fg = pbFinishedFixture();

    $batch = ProductionBatch::withoutEvents(fn () => ProductionBatch::query()->create([
        'batch_number' => 'PB202609090099',
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => null,
        'semi_finished_id' => null,
        'bom_id' => $fg['bom']->id,
        'bom_version' => '1',
        'production_date' => now()->toDateString(),
        'planned_quantity' => 4,
        'actual_output_quantity' => 4,
        'status' => ProductionBatchStatus::Completed,
    ]));

    expect($batch->product_id)->toBeNull()
        ->and($batch->outputDisplayLabel())->toBe($fg['product']->displayLabel());

    $report = app(ProductionBatchProductBackfillService::class)->backfill();

    expect($report['updated'])->toBe(1)
        ->and($batch->fresh()->product_id)->toBe($fg['product']->id)
        ->and((float) $batch->fresh()->actual_output_quantity)->toBe(4.0);
});

it('does not write product_id onto bulk batches and skips conflicting identities', function () {
    $bulk = pbBulkFixture();
    $fg = pbFinishedFixture();
    $other = Product::query()->create([
        'product_name' => 'Other SKU',
        'uom' => 'Nos',
        'dealer_price' => 50,
        'status' => true,
        'manufacturing_enabled' => true,
    ]);

    $sfgBatch = ProductionBatch::query()->create([
        'output_type' => BomOutputType::SemiFinished,
        'product_id' => null,
        'semi_finished_id' => $bulk['semi']->id,
        'bom_id' => $bulk['bom']->id,
        'bom_version' => '1',
        'production_date' => now()->toDateString(),
        'planned_quantity' => 8,
        'actual_output_quantity' => 8,
        'status' => ProductionBatchStatus::Completed,
    ]);

    $conflict = ProductionBatch::withoutEvents(fn () => ProductionBatch::query()->create([
        'batch_number' => 'PB202609090098',
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => null,
        'bom_id' => $fg['bom']->id,
        'bom_version' => '1',
        'production_date' => now()->toDateString(),
        'planned_quantity' => 1,
        'actual_output_quantity' => 1,
        'status' => ProductionBatchStatus::Completed,
    ]));

    StockLedger::query()->create([
        'transaction_date' => now()->toDateString(),
        'transaction_type' => StockTransactionType::ProductionOutput,
        'item_type' => StockItemType::FinishedProduct,
        'product_id' => $other->id,
        'quantity_in' => 1,
        'quantity_out' => 0,
        'stock_before' => 0,
        'stock_after' => 1,
        'rate' => 0,
        'transaction_value' => 0,
        'reference_type' => ProductionBatch::class,
        'reference_id' => $conflict->id,
        'reference_number' => $conflict->batch_number,
    ]);

    $report = app(ProductionBatchProductBackfillService::class)->backfill();

    expect($sfgBatch->fresh()->product_id)->toBeNull()
        ->and($conflict->fresh()->product_id)->toBeNull()
        ->and($report['skipped_sfg'])->toBeGreaterThanOrEqual(1)
        ->and($report['skipped_conflict'])->toBeGreaterThanOrEqual(1)
        ->and((float) $sfgBatch->fresh()->actual_output_quantity)->toBe(8.0);
});

it('posts a new finished batch with product_id and shows it on the list', function () {
    $director = pbProductDisplayDirector();
    $fg = pbFinishedFixture();

    $batch = app(ProductionService::class)->completeProduction([
        'product_id' => $fg['product']->id,
        'planned_quantity' => 3,
        'actual_output_quantity' => 3,
        'production_date' => now()->toDateString(),
        'posting_token' => 'pb-display-new-'.uniqid(),
    ], $director);

    expect($batch->product_id)->toBe($fg['product']->id)
        ->and($batch->outputDisplayLabel())->toBe($fg['product']->fresh()->displayLabel());

    $this->artisan('production:backfill-batch-products', ['--dry-run' => true])
        ->expectsOutputToContain('Skipped (already has product)')
        ->assertSuccessful();
});
