<?php

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\InventoryReportService;
use App\Services\Inventory\InventoryUnitConversion;
use App\Services\Inventory\ProductionService;
use Illuminate\Validation\ValidationException;

function sfDirector(): User
{
    return User::query()->create([
        'name' => 'SF Director',
        'email' => 'sf.director.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
}

function sfSupervisor(): User
{
    return User::query()->create([
        'name' => 'SF Supervisor',
        'email' => 'sf.supervisor.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::ProductionSupervisor->value,
        'job_role' => 'Production Supervisor',
    ]);
}

it('auto-generates unique SFM codes on create', function () {
    $first = SemiFinishedMaterial::query()->create([
        'material_name' => 'Base Mix A',
        'unit' => 'Kg',
        'minimum_stock' => 0,
        'status' => true,
    ]);
    $second = SemiFinishedMaterial::query()->create([
        'material_name' => 'Base Mix B',
        'unit' => 'Kg',
        'minimum_stock' => 0,
        'status' => true,
    ]);

    expect($first->material_code)->toStartWith('SFM')
        ->and($second->material_code)->toStartWith('SFM')
        ->and($first->material_code)->not->toBe($second->material_code)
        ->and((float) $first->current_stock)->toBe(0.0)
        ->and((float) $first->average_production_cost)->toBe(0.0);
});

it('supports bom with semi-finished input and finished product output', function () {
    $raw = RawMaterial::query()->create([
        'material_name' => 'Alloy',
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => 50,
        'minimum_stock' => 1,
        'purchase_rate' => 100,
        'average_rate' => 100,
        'status' => true,
    ]);
    $sf = SemiFinishedMaterial::query()->create([
        'material_name' => 'Pre-mix',
        'unit' => 'Kg',
        'current_stock' => 20,
        'average_production_cost' => 150,
        'current_stock_value' => 3000,
        'minimum_stock' => 2,
        'status' => true,
    ]);
    $product = Product::query()->create([
        'product_name' => 'Finished Ring',
        'product_code' => 'FG-SF-'.uniqid(),
        'category' => 'Jewellery',
        'uom' => 'Nos',
        'production_unit' => 'Nos',
        'status' => true,
        'manufacturing_enabled' => true,
        'current_finished_stock' => 0,
        'weighted_average_cost' => 0,
        'minimum_finished_stock' => 0,
    ]);

    $bom = Bom::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => $product->id,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);
    BomItem::query()->create([
        'bom_id' => $bom->id,
        'item_type' => BomItemType::RawMaterial,
        'raw_material_id' => $raw->id,
        'required_quantity' => 0.5,
        'unit' => 'Kg',
        'sort_order' => 1,
    ]);
    BomItem::query()->create([
        'bom_id' => $bom->id,
        'item_type' => BomItemType::SemiFinished,
        'semi_finished_id' => $sf->id,
        'required_quantity' => 0.2,
        'unit' => 'Kg',
        'sort_order' => 2,
    ]);

    $summary = $bom->fresh()->formulaSummary();
    expect($summary['semi_finished_items'])->toBe(1)
        ->and($summary['estimated_semi_finished_cost'])->toBeGreaterThan(0)
        ->and($summary['estimated_total_bom_cost'])->toBe(
            round($summary['estimated_raw_material_cost'] + $summary['estimated_semi_finished_cost'] + $summary['estimated_packaging_cost'], 2)
        );
});

it('posts semi-finished production with WAVG and consumes SF in finished production', function () {
    $user = sfSupervisor();
    $raw = RawMaterial::query()->create([
        'material_name' => 'Input Alloy',
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => 100,
        'minimum_stock' => 1,
        'purchase_rate' => 100,
        'average_rate' => 100,
        'status' => true,
    ]);
    $pack = PackagingMaterial::query()->create([
        'packaging_name' => 'Bag',
        'category' => 'Bags',
        'unit' => 'Nos',
        'opening_stock' => 100,
        'minimum_stock' => 1,
        'purchase_rate' => 5,
        'average_rate' => 5,
        'status' => true,
    ]);
    $sf = SemiFinishedMaterial::query()->create([
        'material_name' => 'SF Base',
        'unit' => 'Kg',
        'minimum_stock' => 1,
        'status' => true,
    ]);

    $sfBom = Bom::query()->create([
        'output_type' => BomOutputType::SemiFinished,
        'semi_finished_id' => $sf->id,
        'product_id' => null,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);
    BomItem::query()->create([
        'bom_id' => $sfBom->id,
        'item_type' => BomItemType::RawMaterial,
        'raw_material_id' => $raw->id,
        'required_quantity' => 2,
        'unit' => 'Kg',
        'sort_order' => 1,
    ]);

    $service = app(ProductionService::class);
    $sfBatch = $service->completeProduction([
        'output_type' => BomOutputType::SemiFinished->value,
        'semi_finished_id' => $sf->id,
        'planned_quantity' => 10,
        'actual_output_quantity' => 10,
        'production_date' => now()->toDateString(),
        'labour_cost' => 50,
        'transport_cost' => 0,
        'other_manufacturing_cost' => 0,
        'posting_token' => 'sf-token-'.uniqid(),
    ], $user);

    $sf->refresh();
    expect((float) $sf->current_stock)->toBe(10.0)
        ->and((float) $sf->average_production_cost)->toBeGreaterThan(0)
        ->and($sfBatch->semi_finished_ledger_id)->not->toBeNull();

    $ledger = StockLedger::query()->findOrFail($sfBatch->semi_finished_ledger_id);
    expect($ledger->item_type)->toBe(StockItemType::SemiFinished)
        ->and($ledger->transaction_type)->toBe(StockTransactionType::SemiFinishedProduction)
        ->and($ledger->semi_finished_id)->toBe($sf->id)
        ->and($ledger->product_id)->toBeNull()
        ->and($ledger->raw_material_id)->toBeNull()
        ->and((float) $ledger->quantity_in)->toBe(10.0);

    $avgBefore = (float) $sf->average_production_cost;
    $valueBefore = (float) $sf->current_stock_value;

    $product = Product::query()->create([
        'product_name' => 'FG With SF',
        'product_code' => 'FG-WITH-SF-'.uniqid(),
        'category' => 'Jewellery',
        'uom' => 'Nos',
        'production_unit' => 'Nos',
        'status' => true,
        'manufacturing_enabled' => true,
        'current_finished_stock' => 0,
        'weighted_average_cost' => 0,
        'minimum_finished_stock' => 0,
    ]);
    $fgBom = Bom::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => $product->id,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);
    BomItem::query()->create([
        'bom_id' => $fgBom->id,
        'item_type' => BomItemType::SemiFinished,
        'semi_finished_id' => $sf->id,
        'required_quantity' => 1,
        'unit' => 'Kg',
        'sort_order' => 1,
    ]);
    BomItem::query()->create([
        'bom_id' => $fgBom->id,
        'item_type' => BomItemType::PackagingMaterial,
        'packaging_material_id' => $pack->id,
        'required_quantity' => 1,
        'unit' => 'Nos',
        'sort_order' => 2,
    ]);

    $fgBatch = $service->completeProduction([
        'output_type' => BomOutputType::FinishedProduct->value,
        'product_id' => $product->id,
        'planned_quantity' => 5,
        'actual_output_quantity' => 5,
        'production_date' => now()->toDateString(),
        'labour_cost' => 0,
        'transport_cost' => 0,
        'other_manufacturing_cost' => 0,
        'posting_token' => 'fg-sf-token-'.uniqid(),
    ], $user);

    $sf->refresh();
    $product->refresh();

    expect((float) $sf->current_stock)->toBe(5.0)
        ->and((float) $sf->average_production_cost)->toBe($avgBefore)
        ->and((float) $sf->current_stock_value)->toBe(round(5 * $avgBefore, 2))
        ->and((float) $product->current_finished_stock)->toBe(5.0)
        ->and($fgBatch->finished_product_ledger_id)->not->toBeNull();

    $consumption = StockLedger::query()
        ->where('semi_finished_id', $sf->id)
        ->where('transaction_type', StockTransactionType::ProductionConsumption)
        ->where('reference_id', $fgBatch->id)
        ->first();

    expect($consumption)->not->toBeNull()
        ->and((float) $consumption->quantity_out)->toBe(5.0)
        ->and($consumption->product_id)->toBeNull()
        ->and($consumption->raw_material_id)->toBeNull();

    expect($valueBefore)->toBeGreaterThan((float) $sf->current_stock_value);
});

it('prevents negative semi-finished stock on consumption', function () {
    $user = sfSupervisor();
    $sf = SemiFinishedMaterial::query()->create([
        'material_name' => 'Low SF',
        'unit' => 'Kg',
        'current_stock' => 1,
        'average_production_cost' => 10,
        'current_stock_value' => 10,
        'minimum_stock' => 0,
        'status' => true,
    ]);
    $product = Product::query()->create([
        'product_name' => 'Needs SF',
        'product_code' => 'FG-NEG-SF-'.uniqid(),
        'category' => 'Jewellery',
        'uom' => 'Nos',
        'production_unit' => 'Nos',
        'status' => true,
        'manufacturing_enabled' => true,
        'current_finished_stock' => 0,
        'weighted_average_cost' => 0,
        'minimum_finished_stock' => 0,
    ]);
    $bom = Bom::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => $product->id,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);
    BomItem::query()->create([
        'bom_id' => $bom->id,
        'item_type' => BomItemType::SemiFinished,
        'semi_finished_id' => $sf->id,
        'required_quantity' => 2,
        'unit' => 'Kg',
        'sort_order' => 1,
    ]);

    expect(fn () => app(ProductionService::class)->completeProduction([
        'product_id' => $product->id,
        'planned_quantity' => 1,
        'actual_output_quantity' => 1,
        'production_date' => now()->toDateString(),
        'posting_token' => 'neg-sf-'.uniqid(),
    ], $user))->toThrow(ValidationException::class);
});

it('prevents duplicate semi-finished production posting', function () {
    $user = sfSupervisor();
    $raw = RawMaterial::query()->create([
        'material_name' => 'Dup Alloy',
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => 50,
        'minimum_stock' => 1,
        'purchase_rate' => 100,
        'average_rate' => 100,
        'status' => true,
    ]);
    $sf = SemiFinishedMaterial::query()->create([
        'material_name' => 'Dup SF',
        'unit' => 'Kg',
        'minimum_stock' => 0,
        'status' => true,
    ]);
    $bom = Bom::query()->create([
        'output_type' => BomOutputType::SemiFinished,
        'semi_finished_id' => $sf->id,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);
    BomItem::query()->create([
        'bom_id' => $bom->id,
        'item_type' => BomItemType::RawMaterial,
        'raw_material_id' => $raw->id,
        'required_quantity' => 1,
        'unit' => 'Kg',
        'sort_order' => 1,
    ]);

    $token = 'dup-sf-'.uniqid();
    $payload = [
        'output_type' => BomOutputType::SemiFinished->value,
        'semi_finished_id' => $sf->id,
        'planned_quantity' => 2,
        'actual_output_quantity' => 2,
        'production_date' => now()->toDateString(),
        'posting_token' => $token,
    ];

    app(ProductionService::class)->completeProduction($payload, $user);

    expect(fn () => app(ProductionService::class)->completeProduction($payload, $user))
        ->toThrow(ValidationException::class);
});

it('includes semi-finished value card in inventory stock report', function () {
    SemiFinishedMaterial::query()->create([
        'material_name' => 'Report SF',
        'unit' => 'Kg',
        'current_stock' => 4,
        'average_production_cost' => 25,
        'current_stock_value' => 100,
        'minimum_stock' => 1,
        'status' => true,
    ]);

    $result = app(InventoryReportService::class)->build([
        'inventory_type' => InventoryReportService::TYPE_SEMI_FINISHED,
    ]);

    $cards = collect($result->summaryCards)->keyBy('key');
    expect($cards->has('semi_finished_value'))->toBeTrue()
        ->and($cards['semi_finished_value']['filter'])->toBe(InventoryReportService::TYPE_SEMI_FINISHED);

    $rows = $result->query->get();
    expect($rows)->not->toBeEmpty()
        ->and($rows->first()->inventory_type_key)->toBe(InventoryReportService::TYPE_SEMI_FINISHED);
});

it('converts nos and piece 1:1 for semi-finished units', function () {
    $converter = app(InventoryUnitConversion::class);
    $converted = $converter->convert(5, 'Nos', 'Piece');

    expect((float) $converted['quantity'])->toBe(5.0)
        ->and((float) $converted['conversion_factor'])->toBe(1.0);
});

it('keeps finished-goods production unchanged without semi-finished', function () {
    $user = sfSupervisor();
    $raw = RawMaterial::query()->create([
        'material_name' => 'Classic Alloy',
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => 50,
        'minimum_stock' => 1,
        'purchase_rate' => 100,
        'average_rate' => 100,
        'status' => true,
    ]);
    $product = Product::query()->create([
        'product_name' => 'Classic FG',
        'product_code' => 'FG-CLASSIC-'.uniqid(),
        'category' => 'Jewellery',
        'uom' => 'Nos',
        'production_unit' => 'Nos',
        'status' => true,
        'manufacturing_enabled' => true,
        'current_finished_stock' => 0,
        'weighted_average_cost' => 0,
        'minimum_finished_stock' => 0,
    ]);
    $bom = Bom::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => $product->id,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);
    BomItem::query()->create([
        'bom_id' => $bom->id,
        'item_type' => BomItemType::RawMaterial,
        'raw_material_id' => $raw->id,
        'required_quantity' => 1,
        'unit' => 'Kg',
        'sort_order' => 1,
    ]);

    $batch = app(ProductionService::class)->completeProduction([
        'product_id' => $product->id,
        'planned_quantity' => 3,
        'actual_output_quantity' => 3,
        'production_date' => now()->toDateString(),
        'posting_token' => 'classic-fg-'.uniqid(),
    ], $user);

    $product->refresh();
    expect((float) $product->current_finished_stock)->toBe(3.0)
        ->and($batch->finished_product_ledger_id)->not->toBeNull()
        ->and($batch->output_type)->toBe(BomOutputType::FinishedProduct->value)
        ->and($batch->semi_finished_id)->toBeNull();
});
