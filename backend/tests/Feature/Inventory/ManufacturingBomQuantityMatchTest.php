<?php

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Boms\Pages\CreateBom;
use App\Filament\Resources\Boms\Pages\EditBom;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\PackagingMaterial;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Models\User;
use App\Services\Inventory\BOMCalculationService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function manufacturingQtyDirector(): User
{
    return User::query()->create([
        'name' => 'Mfg Qty Director',
        'email' => 'mfg.qty.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
}

/**
 * @return array{raw: RawMaterial, bulk: SemiFinishedMaterial, pack: PackagingMaterial, bom: Bom}
 */
function manufacturingKgBomFixture(float $formulaQty = 200, float $rawQty = 197.6): array
{
    $raw = RawMaterial::query()->create([
        'material_name' => 'NPK Mix',
        'category' => 'Fertilizer',
        'unit' => 'Kg',
        'opening_stock' => 1000,
        'minimum_stock' => 0,
        'purchase_rate' => 50,
        'average_rate' => 50,
        'status' => true,
    ]);
    $pack = PackagingMaterial::query()->create([
        'packaging_name' => 'Pouch',
        'category' => 'Pouches',
        'unit' => 'Nos',
        'opening_stock' => 100,
        'minimum_stock' => 0,
        'purchase_rate' => 5,
        'average_rate' => 5,
        'status' => true,
    ]);
    $bulk = SemiFinishedMaterial::query()->create([
        'material_name' => 'Bulk Mix',
        'unit' => 'Kg',
        'minimum_stock' => 0,
        'status' => true,
    ]);
    $bom = Bom::query()->create([
        'output_type' => BomOutputType::SemiFinished,
        'semi_finished_id' => $bulk->id,
        'product_id' => null,
        'batch_quantity' => $formulaQty,
        'batch_unit' => 'Kg',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);
    BomItem::query()->create([
        'bom_id' => $bom->id,
        'item_type' => BomItemType::RawMaterial,
        'raw_material_id' => $raw->id,
        'required_quantity' => $rawQty,
        'unit' => 'Kg',
        'sort_order' => 1,
    ]);

    return compact('raw', 'bulk', 'pack', 'bom');
}

it('rejects manufacturing bom save when raw plus bulk quantity does not equal formula kg', function () {
    $fixture = manufacturingKgBomFixture(200, 197.6);
    $bom = $fixture['bom']->fresh('items');

    $match = app(BOMCalculationService::class)->manufacturingFormulaQuantityMatch($bom, $bom->items);

    expect($match)->not->toBeNull()
        ->and($match['matched'])->toBeFalse()
        ->and($match['formula_qty_label'])->toBe('200')
        ->and($match['added_qty_label'])->toBe('197.60')
        ->and($match['difference_label'])->toBe('2.40')
        ->and($match['message'])->toBe('Formula quantity mismatch. Required: 200 Kg | Added: 197.60 Kg | Difference: 2.40 Kg');

    expect(fn () => app(BOMCalculationService::class)->assertBomFormulaForSave($bom, $bom->items, BomStatus::Active))
        ->toThrow(ValidationException::class);

    expect(fn () => app(BOMCalculationService::class)->assertBomFormulaForSave($bom, $bom->items, BomStatus::Inactive))
        ->toThrow(ValidationException::class);
});

it('allows manufacturing bom save when raw plus bulk quantity equals formula kg exactly', function () {
    $fixture = manufacturingKgBomFixture(200, 200);
    $bom = $fixture['bom']->fresh('items');

    $match = app(BOMCalculationService::class)->manufacturingFormulaQuantityMatch($bom, $bom->items);

    expect($match['matched'])->toBeTrue()
        ->and($match['remaining'])->toBe(0.0);

    app(BOMCalculationService::class)->assertBomFormulaForSave($bom, $bom->items, BomStatus::Active);
});

it('converts gram formulation to kg and ignores packaging in the manufacturing total', function () {
    $fixture = manufacturingKgBomFixture(200, 197.6);
    $item = $fixture['bom']->items()->first();
    $item->update(['required_quantity' => 197600, 'unit' => 'Gram']);

    BomItem::query()->create([
        'bom_id' => $fixture['bom']->id,
        'item_type' => BomItemType::PackagingMaterial,
        'packaging_material_id' => $fixture['pack']->id,
        'required_quantity' => 50,
        'unit' => 'Nos',
        'sort_order' => 2,
    ]);

    $bom = $fixture['bom']->fresh('items');
    $match = app(BOMCalculationService::class)->manufacturingFormulaQuantityMatch($bom, $bom->items);

    expect($match['added_qty'])->toBe(197.6)
        ->and($match['matched'])->toBeFalse();

    $item->update(['required_quantity' => 200000, 'unit' => 'Gram']);
    $bom = $fixture['bom']->fresh('items');

    expect(app(BOMCalculationService::class)->manufacturingFormulaQuantityMatch($bom, $bom->items)['matched'])->toBeTrue();

    app(BOMCalculationService::class)->assertBomFormulaForSave($bom, $bom->items, BomStatus::Active);
});

it('does not apply manufacturing kg match to packing finished-product boms', function () {
    $fixture = twoStageNutricombiFixture();
    $packing = $fixture['packingBoms'][2]->fresh('items');

    expect(app(BOMCalculationService::class)->manufacturingFormulaQuantityMatch($packing, $packing->items))->toBeNull();

    app(BOMCalculationService::class)->assertBomFormulaForSave($packing, $packing->items, BomStatus::Active);
});

it('includes bulk semi-finished ingredients and excludes packaging from the manufacturing total', function () {
    $fixture = manufacturingKgBomFixture(200, 100);
    $inputBulk = SemiFinishedMaterial::query()->create([
        'material_name' => 'Premix Bulk',
        'unit' => 'Kg',
        'minimum_stock' => 0,
        'status' => true,
    ]);

    BomItem::query()->create([
        'bom_id' => $fixture['bom']->id,
        'item_type' => BomItemType::SemiFinished,
        'semi_finished_id' => $inputBulk->id,
        'required_quantity' => 97.6,
        'unit' => 'Kg',
        'sort_order' => 2,
    ]);
    BomItem::query()->create([
        'bom_id' => $fixture['bom']->id,
        'item_type' => BomItemType::PackagingMaterial,
        'packaging_material_id' => $fixture['pack']->id,
        'required_quantity' => 50,
        'unit' => 'Nos',
        'sort_order' => 3,
    ]);

    $bom = $fixture['bom']->fresh('items');
    $match = app(BOMCalculationService::class)->manufacturingFormulaQuantityMatch($bom, $bom->items);

    expect($match['added_qty'])->toBe(197.6)
        ->and($match['matched'])->toBeFalse()
        ->and($match['message'])->toBe('Formula quantity mismatch. Required: 200 Kg | Added: 197.60 Kg | Difference: 2.40 Kg');
});

it('blocks create and edit when manufacturing formula quantity is not matched', function () {
    $admin = manufacturingQtyDirector();
    $mismatch = manufacturingKgBomFixture(200, 197.6);
    $matched = manufacturingKgBomFixture(200, 200);
    $createBulk = SemiFinishedMaterial::query()->create([
        'material_name' => 'Create Bulk Mix',
        'unit' => 'Kg',
        'minimum_stock' => 0,
        'status' => true,
    ]);

    Livewire::actingAs($admin)
        ->test(CreateBom::class)
        ->assertSuccessful()
        ->fillForm([
            'output_type' => BomOutputType::SemiFinished->value,
            'semi_finished_id' => $createBulk->id,
            'batch_quantity' => 200,
            'batch_unit' => 'Kg',
            'status' => BomStatus::Inactive->value,
            'effective_date' => now()->toDateString(),
            'items' => [
                [
                    'item_type' => BomItemType::RawMaterial->value,
                    'raw_material_id' => $mismatch['raw']->id,
                    'required_quantity' => 197.6,
                    'unit' => 'Kg',
                    'inventory_unit' => 'Kg',
                ],
            ],
        ])
        ->assertSee('Formula Quantity')
        ->assertSee('Added Quantity')
        ->assertSee('197.60')
        ->assertSee('2.40')
        ->call('create')
        ->assertHasFormErrors(['items'])
        ->assertSee('Formula quantity mismatch. Required: 200 Kg | Added: 197.60 Kg | Difference: 2.40 Kg');

    expect(Bom::query()->where('semi_finished_id', $createBulk->id)->exists())->toBeFalse();

    Livewire::actingAs($admin)
        ->test(EditBom::class, ['record' => $mismatch['bom']->getKey()])
        ->assertSuccessful()
        ->assertSee('Added Quantity')
        ->assertSee('197.60')
        ->assertSee('2.40')
        ->call('save')
        ->assertHasFormErrors(['items'])
        ->assertSee('Formula quantity mismatch. Required: 200 Kg | Added: 197.60 Kg | Difference: 2.40 Kg');

    expect((float) $mismatch['bom']->fresh()->items()->value('required_quantity'))->toBe(197.6);

    Livewire::actingAs($admin)
        ->test(EditBom::class, ['record' => $matched['bom']->getKey()])
        ->assertSuccessful()
        ->assertSee('Matched')
        ->call('save')
        ->assertHasNoFormErrors();
});
