<?php

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->director = User::query()->create([
        'name' => 'Product Manufacturing Director',
        'email' => 'product.mfg.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
});

function productManufacturingMaster(array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'product_name' => 'Manufacturing Form Product '.uniqid(),
        'uom' => 'Piece',
        'nos_per_case' => 10,
        'dealer_price' => 100,
        'gst_percentage' => 18,
        'status' => true,
        'manufacturing_enabled' => false,
        'current_finished_stock' => 0,
        'opening_finished_stock' => 0,
        'batch_tracking_enabled' => true,
    ], $overrides));
}

function productManufacturingActiveBom(Product $product): Bom
{
    $raw = RawMaterial::query()->create([
        'material_name' => 'Manufacturing Form RM '.$product->id,
        'category' => 'Fertilizer',
        'unit' => 'Nos',
        'opening_stock' => 0,
        'minimum_stock' => 0,
        'purchase_rate' => 10,
        'average_rate' => 10,
        'status' => true,
    ]);

    $bom = Bom::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => $product->id,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now('Asia/Kolkata')->toDateString(),
        'status' => BomStatus::Active,
    ]);

    BomItem::query()->create([
        'bom_id' => $bom->id,
        'item_type' => BomItemType::RawMaterial,
        'raw_material_id' => $raw->id,
        'required_quantity' => 1,
        'unit' => 'Nos',
        'sort_order' => 1,
    ]);

    return $bom;
}

it('shows the simplified manufacturing fields and hides removed product manufacturing controls', function (): void {
    Livewire::actingAs($this->director)
        ->test(CreateProduct::class)
        ->assertSuccessful()
        ->assertSee('Manufacturing')
        ->assertSee('Minimum Finished Stock')
        ->assertSee('Current Finished Stock')
        ->assertSee('Shelf Life (Days)')
        ->assertSee('Batch Tracking')
        ->assertSee('Weighted Average Cost')
        ->assertDontSee('Manufacturing Enabled')
        ->assertDontSee('Production Unit')
        ->assertDontSee('Standard Batch Size')
        ->assertDontSee('Standard Production Cost')
        ->assertSee('Cases')
        ->assertFormSet([
            'minimum_finished_stock_cases' => 0,
            'batch_tracking_enabled' => true,
            'current_finished_stock_cases' => 0,
            'weighted_average_cost' => 0,
        ]);
});

it('creates a product without a manufacturing enabled toggle and leaves manufacturing off until an active bom exists', function (): void {
    Livewire::actingAs($this->director)
        ->test(CreateProduct::class)
        ->fillForm([
            'product_name' => 'No Bom Manufacturing '.uniqid(),
            'uom' => 'Piece',
            'nos_per_case' => 10,
            'gst_percentage' => '18',
            'dealer_price' => 100,
            'minimum_finished_stock_cases' => 5,
            'shelf_life_days' => 180,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->latest('id')->first();

    expect($product)->not->toBeNull()
        ->and($product->manufacturing_enabled)->toBeFalse()
        ->and((float) $product->minimum_finished_stock)->toBe(50.0)
        ->and((int) $product->shelf_life_days)->toBe(180)
        ->and($product->batch_tracking_enabled)->toBeTrue()
        ->and((float) $product->current_finished_stock)->toBe(0.0)
        ->and((float) $product->weighted_average_cost)->toBe(0.0);
});

it('treats the product as manufacturing enabled when an active bom is saved', function (): void {
    $product = productManufacturingMaster();

    expect($product->manufacturing_enabled)->toBeFalse();

    productManufacturingActiveBom($product);

    expect($product->fresh()->manufacturing_enabled)->toBeTrue();
});

it('keeps manufacturing enabled on product edit when an active bom already exists', function (): void {
    $product = productManufacturingMaster([
        'minimum_finished_stock' => 2,
    ]);
    productManufacturingActiveBom($product);

    expect($product->fresh()->manufacturing_enabled)->toBeTrue();

    Livewire::actingAs($this->director)
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Minimum Finished Stock')
        ->assertSee('Current Finished Stock')
        ->assertSee('Weighted Average Cost')
        ->assertDontSee('Production Unit')
        ->assertDontSee('Standard Batch Size')
        ->assertDontSee('Standard Production Cost')
        ->fillForm([
            'minimum_finished_stock_cases' => 8,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $product->refresh();

    expect($product->manufacturing_enabled)->toBeTrue()
        ->and((float) $product->minimum_finished_stock)->toBe(80.0);
});

it('does not show removed manufacturing fields on the product view page', function (): void {
    $product = productManufacturingMaster();

    Livewire::actingAs($this->director)
        ->test(ViewProduct::class, ['record' => $product->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Minimum Finished Stock')
        ->assertSee('Current Finished Stock')
        ->assertSee('Shelf Life (Days)')
        ->assertSee('Batch Tracking')
        ->assertSee('Weighted Average Cost')
        ->assertDontSee('Manufacturing Enabled')
        ->assertDontSee('Production Unit')
        ->assertDontSee('Standard Batch Size')
        ->assertDontSee('Standard Production Cost');
});
