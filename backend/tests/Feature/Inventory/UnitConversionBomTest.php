<?php

use App\Enums\BomItemType;
use App\Enums\BomStatus;
use App\Filament\Resources\ProductionBatches\Pages\CreateProductionEntry;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\RawMaterial;
use App\Services\Inventory\BOMCalculationService;
use App\Services\Inventory\InventoryUnitConversion;
use App\Services\Inventory\ProductionService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('converts kg formulation to ton inventory stock unit', function () {
    $converter = app(InventoryUnitConversion::class);

    $result = $converter->convert(9, 'Kg', 'Ton');

    expect($result['quantity'])->toBe(0.009)
        ->and($result['conversion_factor'])->toBe(0.001)
        ->and($result['to_unit'])->toBe('Ton');
});

it('converts gram formulation to kg inventory stock unit', function () {
    $result = app(InventoryUnitConversion::class)->convert(500, 'Gram', 'Kg');

    expect($result['quantity'])->toBe(0.5)
        ->and($result['conversion_factor'])->toBe(0.001);
});

it('rejects incompatible weight to volume conversion', function () {
    expect(fn () => app(InventoryUnitConversion::class)->convert(1, 'Kg', 'Litre'))
        ->toThrow(ValidationException::class);

    try {
        app(InventoryUnitConversion::class)->convert(1, 'Kg', 'Litre');
    } catch (ValidationException $e) {
        expect($e->errors()['unit'][0] ?? '')->toContain('Kg cannot be converted to Litre');
    }
});

it('stores bom item inventory equivalent when formulation unit differs', function () {
    $raw = RawMaterial::query()->create([
        'material_name' => 'Zinc Sulphate 33%',
        'category' => 'General',
        'unit' => 'Ton',
        'opening_stock' => 2,
        'minimum_stock' => 0,
        'purchase_rate' => 50000,
        'average_rate' => 50000,
        'status' => true,
    ]);

    $fixture = seedManufacturingFixture();
    $item = BomItem::query()->create([
        'bom_id' => $fixture['bom']->id,
        'item_type' => BomItemType::RawMaterial,
        'raw_material_id' => $raw->id,
        'required_quantity' => 9,
        'unit' => 'Kg',
        'is_optional' => false,
        'sort_order' => 10,
    ]);

    $item->refresh();

    expect((float) $item->inventory_equivalent_quantity)->toBe(0.009)
        ->and($item->inventory_unit)->toBe('Ton')
        ->and((float) $item->conversion_factor)->toBe(0.001)
        ->and($item->unit)->toBe('Kg')
        ->and((float) $item->required_quantity)->toBe(9.0);
});

it('scales production then converts formulation qty to inventory unit', function () {
    $raw = RawMaterial::query()->create([
        'material_name' => 'Zinc Sulphate Bulk',
        'category' => 'General',
        'unit' => 'Ton',
        'opening_stock' => 2,
        'minimum_stock' => 0,
        'purchase_rate' => 50000,
        'average_rate' => 50000,
        'status' => true,
        'current_stock' => 2,
        'current_stock_value' => 100000,
    ]);

    $fixture = seedManufacturingFixture(rawStock: 1000, packStock: 1000);
    $fixture['bom']->update(['batch_quantity' => 100, 'batch_unit' => 'Nos']);
    $fixture['bom']->items()->where('item_type', BomItemType::RawMaterial)->update([
        'raw_material_id' => $raw->id,
        'required_quantity' => 900,
        'unit' => 'Kg',
    ]);
    // Force recalculation via model save
    $rawItem = $fixture['bom']->items()->where('item_type', BomItemType::RawMaterial)->first();
    $rawItem->raw_material_id = $raw->id;
    $rawItem->required_quantity = 900;
    $rawItem->unit = 'Kg';
    $rawItem->save();

    $rows = app(BOMCalculationService::class)->explodeRequirements(
        $fixture['bom']->fresh()->load('items.rawMaterial', 'items.packagingMaterial'),
        50,
    );

    $rawRow = collect($rows)->firstWhere('item_type', BomItemType::RawMaterial->value);

    // Scaled formulation: 900/100*50 = 450 Kg → 0.450 Ton
    expect($rawRow['formulation_quantity'])->toBe(450.0)
        ->and($rawRow['formulation_unit'])->toBe('Kg')
        ->and($rawRow['required_quantity'])->toBe(0.45)
        ->and($rawRow['inventory_unit'])->toBe('Ton')
        ->and($rawRow['available_stock'])->toBe(2.0)
        ->and($rawRow['balance_after'])->toBe(1.55)
        ->and($rawRow['estimated_value'])->toBe(22500.0); // 0.45 * 50000
});

it('deducts inventory-equivalent quantity on production completion', function () {
    $raw = RawMaterial::query()->create([
        'material_name' => 'Zinc Deduct',
        'category' => 'General',
        'unit' => 'Ton',
        'opening_stock' => 1,
        'current_stock' => 1,
        'current_stock_value' => 50000,
        'minimum_stock' => 0,
        'purchase_rate' => 50000,
        'average_rate' => 50000,
        'status' => true,
    ]);

    $fixture = seedManufacturingFixture(rawStock: 1000, packStock: 1000);
    $fixture['bom']->update(['batch_quantity' => 100, 'batch_unit' => 'Nos']);

    $rawItem = $fixture['bom']->items()->where('item_type', BomItemType::RawMaterial)->first();
    $rawItem->raw_material_id = $raw->id;
    $rawItem->required_quantity = 9;
    $rawItem->unit = 'Kg';
    $rawItem->save();

    // Production qty 100 → scaled 9 Kg → 0.009 Ton
    $batch = app(ProductionService::class)->completeProduction([
        'product_id' => $fixture['product']->id,
        'planned_quantity' => 100,
        'actual_output_quantity' => 100,
        'production_date' => now()->toDateString(),
        'labour_cost' => 0,
    ], inventorySupervisor());

    $consumption = $batch->consumptions->firstWhere('raw_material_id', $raw->id);

    expect((float) $raw->fresh()->current_stock)->toBe(0.991)
        ->and((float) $consumption->consumed_quantity)->toBe(0.009)
        ->and($consumption->inventory_unit)->toBe('Ton')
        ->and((float) $consumption->formulation_quantity)->toBe(9.0)
        ->and($consumption->formulation_unit)->toBe('Kg')
        ->and((float) $consumption->consumption_value)->toBe(450.0);
});

it('keeps review actual used qty in the required qty uom when inventory stock is in tons', function () {
    $raw = RawMaterial::query()->create([
        'material_name' => 'Zinc Review Uom',
        'category' => 'General',
        'unit' => 'Ton',
        'opening_stock' => 2,
        'current_stock' => 2,
        'current_stock_value' => 100000,
        'minimum_stock' => 0,
        'purchase_rate' => 50000,
        'average_rate' => 50000,
        'status' => true,
    ]);

    $fixture = seedManufacturingFixture(rawStock: 1000, packStock: 1000);
    $fixture['bom']->update(['batch_quantity' => 100, 'batch_unit' => 'Nos']);

    $rawItem = $fixture['bom']->items()->where('item_type', BomItemType::RawMaterial)->first();
    $rawItem->raw_material_id = $raw->id;
    $rawItem->required_quantity = 900;
    $rawItem->unit = 'Kg';
    $rawItem->save();

    $this->actingAs(inventorySupervisor());

    $component = Livewire::test(CreateProductionEntry::class)
        ->fillForm([
            'product_id' => $fixture['product']->id,
            'production_quantity' => 50,
            'production_date' => now('Asia/Kolkata')->toDateString(),
            'labour_cost' => 0,
            'transport_cost' => 0,
            'other_manufacturing_cost' => 0,
        ]);

    expect($component->instance()->prepareReview())->toBeTrue();

    $page = $component->instance();
    $rawIndex = collect($page->requirements)->search(
        fn (array $row): bool => ($row['item_type'] ?? '') === BomItemType::RawMaterial->value,
    );
    $row = $page->requirements[$rawIndex];
    $panel = collect($page->reviewPanelData()['materialRows'])->firstWhere('index', $rawIndex);

    // Required 450 Kg; stock 2 Ton = 2000 Kg, so default actual used is 450 Kg (not 0.45 Ton).
    expect((float) $row['formulation_quantity'])->toBe(450.0)
        ->and($row['formulation_unit'])->toBe('Kg')
        ->and((float) $row['required_quantity'])->toBe(0.45)
        ->and($row['inventory_unit'])->toBe('Ton')
        ->and((float) $row['actual_used_formulation_quantity'])->toBe(450.0)
        ->and((float) $row['actual_used_quantity'])->toBe(0.45)
        ->and($panel['required_label'])->toBe('450.000 Kg')
        ->and($panel['formulation_unit'])->toBe('Kg')
        ->and($panel['formulation_unit'])->not->toBe('Ton')
        ->and((float) $panel['max_actual_used'])->toBe(450.0)
        ->and($panel['average_rate_label'])->toContain('/Kg')
        ->and((float) $row['estimated_value'])->toBe(22500.0);

    $page->requirements[$rawIndex]['actual_used_formulation_quantity'] = 200;
    $page->recostReviewFromActualUsed(fromFormulation: true);
    $used = $page->requirements[$rawIndex];

    expect((float) $used['actual_used_formulation_quantity'])->toBe(200.0)
        ->and((float) $used['actual_used_quantity'])->toBe(0.2)
        ->and((float) $used['estimated_value'])->toBe(10000.0)
        ->and((float) $used['balance_after'])->toBe(1.8)
        ->and((float) $used['required_quantity'])->toBe(0.45);

    $page->requirements[$rawIndex]['actual_used_formulation_quantity'] = 3000;
    $page->recostReviewFromActualUsed(fromFormulation: true);
    $clamped = $page->requirements[$rawIndex];

    expect((float) $clamped['actual_used_formulation_quantity'])->toBe(450.0)
        ->and((float) $clamped['actual_used_quantity'])->toBe(0.45);
});

it('rejects activating bom with incompatible formulation unit', function () {
    $fixture = seedManufacturingFixture();
    $rawItem = $fixture['bom']->items()->where('item_type', BomItemType::RawMaterial)->first();

    // Bypass model saving conversion by updating quietly then asserting structure
    expect(fn () => app(BOMCalculationService::class)->assertBomStructure(
        [
            'product_id' => $fixture['product']->id,
            'batch_quantity' => 100,
            'batch_unit' => 'Nos',
        ],
        [[
            'item_type' => BomItemType::RawMaterial->value,
            'raw_material_id' => $rawItem->raw_material_id,
            'required_quantity' => 1,
            'unit' => 'Litre',
        ]],
        requireActivationRules: true,
    ))->toThrow(ValidationException::class);
});
