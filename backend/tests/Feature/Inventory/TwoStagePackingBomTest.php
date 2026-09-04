<?php

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Filament\Resources\Boms\Pages\CreateBom;
use App\Filament\Resources\Boms\Pages\EditBom;
use App\Filament\Resources\Boms\Pages\ListBoms;
use App\Filament\Resources\Boms\Pages\ViewBom;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\BOMCalculationService;
use App\Services\Inventory\ProductionService;
use Livewire\Livewire;

function twoStageNutricombiFixture(): array
{
    $raw = RawMaterial::query()->create([
        'material_name' => 'NPK Mix',
        'category' => 'Fertilizer',
        'unit' => 'Kg',
        'opening_stock' => 1000,
        'minimum_stock' => 10,
        'purchase_rate' => 50,
        'average_rate' => 50,
        'status' => true,
    ]);

    $pack2 = PackagingMaterial::query()->create([
        'packaging_name' => '2 KG Pouch',
        'category' => 'Pouches',
        'unit' => 'Nos',
        'opening_stock' => 500,
        'minimum_stock' => 10,
        'purchase_rate' => 5,
        'average_rate' => 5,
        'status' => true,
    ]);
    $pack5 = PackagingMaterial::query()->create([
        'packaging_name' => '5 KG Pouch',
        'category' => 'Pouches',
        'unit' => 'Nos',
        'opening_stock' => 500,
        'minimum_stock' => 10,
        'purchase_rate' => 8,
        'average_rate' => 8,
        'status' => true,
    ]);
    $pack10 = PackagingMaterial::query()->create([
        'packaging_name' => '10 KG Pouch',
        'category' => 'Pouches',
        'unit' => 'Nos',
        'opening_stock' => 500,
        'minimum_stock' => 10,
        'purchase_rate' => 12,
        'average_rate' => 12,
        'status' => true,
    ]);

    $bulk = SemiFinishedMaterial::query()->create([
        'material_name' => 'Nutricombi Drip Mix Bulk',
        'unit' => 'Kg',
        'minimum_stock' => 10,
        'status' => true,
    ]);

    $mfgBom = Bom::query()->create([
        'output_type' => BomOutputType::SemiFinished,
        'semi_finished_id' => $bulk->id,
        'product_id' => null,
        'batch_quantity' => 100,
        'batch_unit' => 'Kg',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);
    BomItem::query()->create([
        'bom_id' => $mfgBom->id,
        'item_type' => BomItemType::RawMaterial,
        'raw_material_id' => $raw->id,
        'required_quantity' => 100,
        'unit' => 'Kg',
        'sort_order' => 1,
    ]);

    $sizes = [
        2 => $pack2,
        5 => $pack5,
        10 => $pack10,
    ];
    $products = [];
    $packingBoms = [];

    foreach ($sizes as $kg => $pack) {
        $product = Product::query()->create([
            'product_name' => 'Nutricombi Drip Mix '.$kg.' KG',
            'product_code' => 'NDM-'.$kg.'KG-'.uniqid(),
            'uom' => 'Nos',
            'production_unit' => 'Nos',
            'dealer_price' => 100 * $kg,
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
            'semi_finished_id' => $bulk->id,
            'required_quantity' => $kg,
            'unit' => 'Kg',
            'sort_order' => 1,
        ]);
        BomItem::query()->create([
            'bom_id' => $bom->id,
            'item_type' => BomItemType::PackagingMaterial,
            'packaging_material_id' => $pack->id,
            'required_quantity' => 1,
            'unit' => 'Nos',
            'sort_order' => 2,
        ]);
        $products[$kg] = $product;
        $packingBoms[$kg] = $bom;
    }

    return compact('raw', 'bulk', 'mfgBom', 'products', 'packingBoms', 'pack2', 'pack5', 'pack10');
}

it('activates a manufacturing bom defined in kg without duplicating it per packing size', function () {
    $fixture = twoStageNutricombiFixture();

    app(BOMCalculationService::class)->assertBomCanBeActivated($fixture['mfgBom']->fresh(['items', 'semiFinished']));

    expect(Bom::query()->where('semi_finished_id', $fixture['bulk']->id)->count())->toBe(1)
        ->and(Bom::query()->where('output_type', BomOutputType::FinishedProduct)->count())->toBe(3);
});

it('estimates packing sku cost as bulk consumed plus packing materials from the shared formula', function () {
    $fixture = twoStageNutricombiFixture();
    $calc = app(BOMCalculationService::class);

    $mfg = $calc->summarizeBom($fixture['mfgBom']->fresh(), $fixture['mfgBom']->fresh('items')->items);
    expect($mfg['estimated_total_bom_cost'])->toBe(5000.0)
        ->and($mfg['estimated_cost_per_finished_unit'])->toBe(50.0);

    $twoKg = $calc->summarizeBom(
        $fixture['packingBoms'][2]->fresh(),
        $fixture['packingBoms'][2]->fresh('items')->items,
    );

    expect($twoKg['semi_finished_items'])->toBe(1)
        ->and($twoKg['raw_material_items'])->toBe(0)
        ->and($twoKg['estimated_semi_finished_cost'])->toBe(100.0)
        ->and($twoKg['estimated_packaging_cost'])->toBe(5.0)
        ->and($twoKg['estimated_cost_per_finished_unit'])->toBe(105.0);

    $fiveKg = $calc->summarizeBom(
        $fixture['packingBoms'][5]->fresh(),
        $fixture['packingBoms'][5]->fresh('items')->items,
    );
    expect($fiveKg['estimated_semi_finished_cost'])->toBe(250.0)
        ->and($fiveKg['estimated_packaging_cost'])->toBe(8.0)
        ->and($fiveKg['estimated_cost_per_finished_unit'])->toBe(258.0);

    $tenKg = $calc->summarizeBom(
        $fixture['packingBoms'][10]->fresh(),
        $fixture['packingBoms'][10]->fresh('items')->items,
    );
    expect($tenKg['estimated_cost_per_finished_unit'])->toBe(512.0);
});

it('consumes bulk stock according to the selected packing size', function () {
    $fixture = twoStageNutricombiFixture();
    $user = User::query()->create([
        'name' => 'Two Stage Director',
        'email' => 'twostage.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
    $service = app(ProductionService::class);

    $service->completeProduction([
        'output_type' => BomOutputType::SemiFinished->value,
        'semi_finished_id' => $fixture['bulk']->id,
        'planned_quantity' => 100,
        'actual_output_quantity' => 100,
        'production_date' => now()->toDateString(),
        'labour_cost' => 0,
        'transport_cost' => 0,
        'other_manufacturing_cost' => 0,
        'posting_token' => 'bulk-'.uniqid(),
    ], $user);

    expect((float) $fixture['bulk']->fresh()->current_stock)->toBe(100.0)
        ->and((float) $fixture['raw']->fresh()->current_stock)->toBe(900.0);

    $service->completeProduction([
        'output_type' => BomOutputType::FinishedProduct->value,
        'product_id' => $fixture['products'][2]->id,
        'planned_quantity' => 3,
        'actual_output_quantity' => 3,
        'production_date' => now()->toDateString(),
        'labour_cost' => 0,
        'transport_cost' => 0,
        'other_manufacturing_cost' => 0,
        'posting_token' => 'pack-2kg-'.uniqid(),
    ], $user);

    $bulk = $fixture['bulk']->fresh();
    $product = $fixture['products'][2]->fresh();

    expect((float) $bulk->current_stock)->toBe(94.0)
        ->and((float) $product->current_finished_stock)->toBe(3.0)
        ->and((float) $fixture['pack2']->fresh()->current_stock)->toBe(497.0);

    $consumption = StockLedger::query()
        ->where('semi_finished_id', $bulk->id)
        ->where('transaction_type', StockTransactionType::ProductionConsumption)
        ->first();

    expect($consumption)->not->toBeNull()
        ->and((float) $consumption->quantity_out)->toBe(6.0);
});

it('shows packing sku estimated cost on the bom list without a stored cost field', function () {
    $fixture = twoStageNutricombiFixture();
    $admin = User::query()->create([
        'name' => 'Two Stage List Director',
        'email' => 'twostage.list.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);

    Livewire::actingAs($admin)
        ->test(ListBoms::class)
        ->assertSuccessful()
        ->assertSee('Manufacturing / Semi-Finished')
        ->assertSee('Packing / Finished Product')
        ->assertSee('Nutricombi Drip Mix Bulk')
        ->assertDontSee('Nutricombi Drip Mix 2 KG')
        ->set('activeTab', 'packing')
        ->assertSee('Nutricombi Drip Mix 2 KG')
        ->assertDontSee('Nutricombi Drip Mix Bulk')
        ->assertSee('₹105.00')
        ->assertSee('₹258.00')
        ->assertSee('₹512.00');
});

it('separates manufacturing and packing boms with list tabs', function () {
    $fixture = twoStageNutricombiFixture();
    $admin = User::query()->create([
        'name' => 'Two Stage Tabs Director',
        'email' => 'twostage.tabs.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);

    Livewire::actingAs($admin)
        ->test(ListBoms::class)
        ->assertSuccessful()
        ->assertSee('Manufacturing / Semi-Finished')
        ->assertSee('Packing / Finished Product')
        ->assertCanSeeTableRecords([$fixture['mfgBom']])
        ->assertCanNotSeeTableRecords([
            $fixture['packingBoms'][2],
            $fixture['packingBoms'][5],
            $fixture['packingBoms'][10],
        ])
        ->set('activeTab', 'packing')
        ->assertCanSeeTableRecords([
            $fixture['packingBoms'][2],
            $fixture['packingBoms'][5],
            $fixture['packingBoms'][10],
        ])
        ->assertCanNotSeeTableRecords([$fixture['mfgBom']]);
});

it('labels the bom formula field from the selected batch unit', function () {
    $admin = User::query()->create([
        'name' => 'Two Stage Label Director',
        'email' => 'twostage.label.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);

    Livewire::actingAs($admin)
        ->test(CreateBom::class)
        ->assertSuccessful()
        ->assertSee('Formula For Quantity')
        ->fillForm([
            'output_type' => BomOutputType::SemiFinished->value,
            'batch_unit' => 'Kg',
            'batch_quantity' => 462,
        ])
        ->assertSee('Formula For Kg')
        ->assertSee('Total Kg of semi-finished output this BOM formula produces.')
        ->fillForm([
            'batch_unit' => 'Litre',
        ])
        ->assertSee('Formula For Ltr')
        ->assertSee('Total Ltr of semi-finished output this BOM formula produces.');
});

it('shows formula for kg on a manufacturing bom view and edit page', function () {
    $fixture = twoStageNutricombiFixture();
    $admin = User::query()->create([
        'name' => 'Two Stage View Director',
        'email' => 'twostage.view.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);

    Livewire::actingAs($admin)
        ->test(EditBom::class, ['record' => $fixture['mfgBom']->getKey()])
        ->assertSuccessful()
        ->assertSee('Formula For Kg')
        ->assertSee('Total Kg of semi-finished output this BOM formula produces.');

    Livewire::actingAs($admin)
        ->test(ViewBom::class, ['record' => $fixture['mfgBom']->getKey()])
        ->assertSuccessful()
        ->assertSee('Formula For')
        ->assertSee('Formula Quantity')
        ->assertSee('100 Kg')
        ->assertSee('Item Type')
        ->assertSee('Required Qty')
        ->assertSee('Inventory Equivalent')
        ->assertSee('Estimated Total BOM Cost')
        ->assertSee('Estimated Cost / Unit')
        ->assertSee('₹5,000.00')
        ->assertSee('₹50.00')
        ->assertSee('Edit');

    Livewire::actingAs($admin)
        ->test(EditBom::class, ['record' => $fixture['packingBoms'][2]->getKey()])
        ->assertSuccessful()
        ->assertSee('Formula For Quantity');

    Livewire::actingAs($admin)
        ->test(ViewBom::class, ['record' => $fixture['packingBoms'][2]->getKey()])
        ->assertSuccessful()
        ->assertSee('Packing (Finished Product)')
        ->assertSee('Nutricombi Drip Mix 2 KG')
        ->assertSee('Formula For')
        ->assertSee('Quantity')
        ->assertSee('1 Nos')
        ->assertSee('Bulk / Semi-Finished')
        ->assertSee('Packaging Material')
        ->assertSee('₹105.00');
});

it('hides estimated costs on the bom view from users who cannot view production costs', function () {
    $fixture = twoStageNutricombiFixture();
    $supervisor = User::query()->create([
        'name' => 'Two Stage View Supervisor',
        'email' => 'twostage.view.sup.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::ProductionSupervisor->value,
        'job_role' => 'Production Supervisor',
    ]);

    Livewire::actingAs($supervisor)
        ->test(ViewBom::class, ['record' => $fixture['mfgBom']->getKey()])
        ->assertSuccessful()
        ->assertSee('BOM Items')
        ->assertSee('BOM Summary')
        ->assertSee('Formula For')
        ->assertDontSee('Estimated Total BOM Cost')
        ->assertDontSee('Estimated Cost / Unit')
        ->assertDontSee('Estimated Raw Material Cost');
});
