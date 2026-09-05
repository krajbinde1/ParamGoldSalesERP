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

it('defaults the product list to active products and can show inactive or all', function () {
    $admin = productListBomDirector();
    $active = productListBomProduct('Active Listed '.uniqid());
    $inactive = productListBomProduct('Inactive Hidden '.uniqid());
    $inactive->update(['status' => false]);

    Livewire::actingAs($admin)
        ->test(ListProducts::class)
        ->assertSuccessful()
        ->assertSee('Active')
        ->assertSee('Inactive')
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$inactive])
        ->filterTable('status', false)
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$active])
        ->filterTable('status', null)
        ->assertCanSeeTableRecords([$active, $inactive]);
});

it('filters the product list by bom status from the column header and combines with status', function () {
    $admin = productListBomDirector();
    $withBom = productListBomProduct('Header BOM Set '.uniqid());
    $withoutBom = productListBomProduct('Header BOM Not Set '.uniqid());
    $inactiveWithBom = productListBomProduct('Inactive BOM Set '.uniqid());
    $inactiveWithBom->update(['status' => false]);

    Bom::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => $withBom->id,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);
    Bom::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => $inactiveWithBom->id,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);

    Livewire::actingAs($admin)
        ->test(ListProducts::class)
        ->assertSuccessful()
        ->assertSeeHtml('tableFilters.bom_status.value')
        ->assertSeeHtml('Filter by BOM Status')
        ->assertCanSeeTableRecords([$withBom, $withoutBom])
        ->assertCanNotSeeTableRecords([$inactiveWithBom])
        ->filterTable('bom_status', 'set')
        ->assertCanSeeTableRecords([$withBom])
        ->assertCanNotSeeTableRecords([$withoutBom, $inactiveWithBom])
        ->filterTable('bom_status', 'not_set')
        ->assertCanSeeTableRecords([$withoutBom])
        ->assertCanNotSeeTableRecords([$withBom, $inactiveWithBom])
        ->filterTable('status', null)
        ->filterTable('bom_status', 'set')
        ->assertCanSeeTableRecords([$withBom, $inactiveWithBom])
        ->assertCanNotSeeTableRecords([$withoutBom]);
});

it('keeps product list status and bom filters after editing a product', function () {
    $admin = productListBomDirector();
    $inactive = productListBomProduct('Return Filter Product '.uniqid());
    $inactive->update(['status' => false, 'gst_percentage' => 12, 'uom' => 'Kg']);

    $listUrl = \App\Filament\Resources\Products\ProductResource::getUrl('index').'?tableSearch=ReturnFilter';

    Livewire::actingAs($admin)
        ->withQueryParams(['returnUrl' => $listUrl])
        ->test(\App\Filament\Resources\Products\Pages\EditProduct::class, ['record' => $inactive->getRouteKey()])
        ->assertSuccessful()
        ->assertFormSet(['gst_percentage' => '12'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect($listUrl);

    expect($inactive->fresh()->status)->toBeFalse();
});
