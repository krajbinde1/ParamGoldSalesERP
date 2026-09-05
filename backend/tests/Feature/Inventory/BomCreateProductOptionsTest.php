<?php

use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Boms\Pages\CreateBom;
use App\Filament\Resources\Boms\Pages\EditBom;
use App\Filament\Resources\Boms\Schemas\BomForm;
use App\Models\Bom;
use App\Models\Product;
use App\Models\SemiFinishedMaterial;
use App\Models\User;
use Livewire\Livewire;

function bomOptionsDirector(): User
{
    return User::query()->create([
        'name' => 'BOM Options Director',
        'email' => 'bom.options.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
}

function bomOptionsProduct(string $name, bool $active = true): Product
{
    return Product::query()->create([
        'product_name' => $name,
        'uom' => 'Kg',
        'nos_per_case' => 1,
        'dealer_price' => 100,
        'gst_percentage' => 18,
        'status' => $active,
    ]);
}

it('offers only active finished products without a packing bom on create', function () {
    $admin = bomOptionsDirector();
    $available = bomOptionsProduct('Available Pack SKU '.uniqid());
    $taken = bomOptionsProduct('Taken Pack SKU '.uniqid());
    $inactive = bomOptionsProduct('Inactive Pack SKU '.uniqid(), false);

    Bom::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => $taken->id,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);

    $ids = BomForm::availableFinishedProductsQuery(Product::query())->pluck('id');

    expect($ids)->toContain($available->id)
        ->and($ids)->not->toContain($taken->id)
        ->and($ids)->not->toContain($inactive->id);

    Livewire::actingAs($admin)
        ->test(CreateBom::class)
        ->assertSuccessful()
        ->assertSee($available->product_name)
        ->assertDontSee($taken->product_name)
        ->assertDontSee($inactive->product_name);
});

it('offers only active semi-finished materials without a manufacturing bom on create', function () {
    $available = SemiFinishedMaterial::query()->create([
        'material_name' => 'Available Bulk '.uniqid(),
        'unit' => 'Kg',
        'minimum_stock' => 0,
        'status' => true,
    ]);
    $taken = SemiFinishedMaterial::query()->create([
        'material_name' => 'Taken Bulk '.uniqid(),
        'unit' => 'Kg',
        'minimum_stock' => 0,
        'status' => true,
    ]);
    $inactive = SemiFinishedMaterial::query()->create([
        'material_name' => 'Inactive Bulk '.uniqid(),
        'unit' => 'Kg',
        'minimum_stock' => 0,
        'status' => false,
    ]);

    Bom::query()->create([
        'output_type' => BomOutputType::SemiFinished,
        'semi_finished_id' => $taken->id,
        'product_id' => null,
        'batch_quantity' => 100,
        'batch_unit' => 'Kg',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);

    $ids = BomForm::availableSemiFinishedQuery(SemiFinishedMaterial::query())->pluck('id');

    expect($ids)->toContain($available->id)
        ->and($ids)->not->toContain($taken->id)
        ->and($ids)->not->toContain($inactive->id);
});

it('keeps the current finished product selected when editing a packing bom', function () {
    $admin = bomOptionsDirector();
    $current = bomOptionsProduct('Current Pack SKU '.uniqid());
    $otherTaken = bomOptionsProduct('Other Taken Pack SKU '.uniqid());

    $bom = Bom::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => $current->id,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);
    Bom::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => $otherTaken->id,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);

    Livewire::actingAs($admin)
        ->test(EditBom::class, ['record' => $bom->getRouteKey()])
        ->assertSuccessful()
        ->assertFormSet(['product_id' => $current->id])
        ->assertSee($current->product_name)
        ->assertDontSee($otherTaken->product_name);
});
