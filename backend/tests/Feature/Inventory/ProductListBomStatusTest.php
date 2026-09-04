<?php

use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Boms\BomResource;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Bom;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

function productListBomDirector(): User
{
    return User::query()->create([
        'name' => 'Product BOM List Director',
        'email' => 'product.bom.list.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
}

function productListBomProduct(string $name): Product
{
    return Product::query()->create([
        'product_name' => $name,
        'uom' => 'Nos',
        'nos_per_case' => 10,
        'dealer_price' => 100,
        'status' => true,
    ]);
}

it('shows bom set after nos/case when the product has an active packing bom and links to bom view', function () {
    $admin = productListBomDirector();
    $withBom = productListBomProduct('Packing SKU With BOM '.uniqid());
    $withoutBom = productListBomProduct('Packing SKU Without BOM '.uniqid());
    $inactiveOnly = productListBomProduct('Packing SKU Inactive BOM '.uniqid());

    $activeBom = Bom::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => $withBom->id,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);
    Bom::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => $inactiveOnly->id,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Inactive,
    ]);

    $bomViewUrl = BomResource::getUrl('view', ['record' => $activeBom]);

    Livewire::actingAs($admin)
        ->test(ListProducts::class)
        ->assertSuccessful()
        ->assertSee('BOM Status')
        ->assertSee('BOM Set')
        ->assertSee('BOM Not Set')
        ->assertSeeHtml($bomViewUrl)
        ->assertCanSeeTableRecords([$withBom, $withoutBom, $inactiveOnly]);

    expect($withBom->fresh()->activeBom?->is($activeBom))->toBeTrue()
        ->and($withoutBom->fresh()->activeBom)->toBeNull()
        ->and($inactiveOnly->fresh()->activeBom)->toBeNull();
});
