<?php

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\StockLedger;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->director = User::query()->create([
        'name' => 'Product Opening Director',
        'email' => 'product.opening.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
});

function productOpeningMaster(array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'product_name' => 'Sales Opening Product '.uniqid(),
        'uom' => 'Piece',
        'nos_per_case' => 20,
        'dealer_price' => 100,
        'gst_percentage' => 18,
        'status' => true,
        'manufacturing_enabled' => false,
        'current_finished_stock' => 0,
        'opening_finished_stock' => 0,
    ], $overrides));
}

function productOpeningActiveBom(Product $product, float $costPerNos = 25): Bom
{
    $raw = RawMaterial::query()->create([
        'material_name' => 'Sales Opening RM '.$product->id,
        'category' => 'Fertilizer',
        'unit' => 'Nos',
        'opening_stock' => 0,
        'minimum_stock' => 0,
        'purchase_rate' => $costPerNos,
        'average_rate' => $costPerNos,
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

    return $bom->fresh(['items.rawMaterial']);
}

it('renders opening stock fields on sales product create even before values are entered', function (): void {
    Livewire::actingAs($this->director)
        ->test(CreateProduct::class)
        ->assertSuccessful()
        ->assertSee('Opening Stock')
        ->assertSee('Opening Stock (Cases)')
        ->assertSee('Opening Qty (Nos)')
        ->assertSee('Average Cost/Nos')
        ->assertSee('Opening Stock Value')
        ->assertSee('As On Date')
        ->assertFormSet([
            'opening_stock_cases' => 0,
        ]);
});

it('renders opening stock fields on sales product edit when manufacturing is off and stock is zero', function (): void {
    $product = productOpeningMaster([
        'manufacturing_enabled' => false,
        'opening_finished_stock' => 0,
        'current_finished_stock' => 0,
    ]);

    Livewire::actingAs($this->director)
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Opening Stock')
        ->assertSee('Opening Stock (Cases)')
        ->assertSee('Opening Qty (Nos)')
        ->assertSee('Average Cost/Nos')
        ->assertSee('Opening Stock Value')
        ->assertSee('As On Date')
        ->assertFormSet([
            'opening_stock_cases' => 0,
            'opening_stock_quantity' => 0,
        ]);
});

it('creates a sales product without posting opening stock when cases are zero', function (): void {
    Livewire::actingAs($this->director)
        ->test(CreateProduct::class)
        ->fillForm([
            'product_name' => 'Create Zero Opening '.uniqid(),
            'uom' => 'Piece',
            'nos_per_case' => 10,
            'gst_percentage' => '18',
            'dealer_price' => 100,
            'opening_stock_cases' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->latest('id')->first();

    expect($product)->not->toBeNull()
        ->and((float) $product->opening_finished_stock)->toBe(0.0)
        ->and(StockLedger::query()->where('product_id', $product->id)->count())->toBe(0);
});

it('does not save opening stock from product create when there is no active bom', function (): void {
    Livewire::actingAs($this->director)
        ->test(CreateProduct::class)
        ->fillForm([
            'product_name' => 'Create No Bom Opening '.uniqid(),
            'uom' => 'Piece',
            'nos_per_case' => 20,
            'gst_percentage' => '18',
            'dealer_price' => 100,
            'opening_stock_cases' => 10,
            'opening_date' => now('Asia/Kolkata')->toDateString(),
        ])
        ->call('create')
        ->assertHasFormErrors(['opening_stock_cases']);

    expect(Product::query()->where('product_name', 'like', 'Create No Bom Opening %')->count())->toBe(0);
});

it('posts opening stock from sales product edit using active bom estimated cost', function (): void {
    $product = productOpeningMaster([
        'uom' => 'Piece',
        'nos_per_case' => 20,
        'manufacturing_enabled' => false,
    ]);
    productOpeningActiveBom($product, 25);

    Livewire::actingAs($this->director)
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertFormSet([
            'opening_average_cost' => 25,
        ])
        ->fillForm([
            'opening_stock_cases' => 10,
            'opening_date' => now('Asia/Kolkata')->toDateString(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $product->refresh();
    $ledger = StockLedger::query()
        ->where('product_id', $product->id)
        ->where('transaction_type', StockTransactionType::OpeningStock->value)
        ->get();

    expect((float) $product->opening_finished_stock)->toBe(200.0)
        ->and((float) $product->current_finished_stock)->toBe(200.0)
        ->and((float) $product->weighted_average_cost)->toBe(25.0)
        ->and($product->manufacturing_enabled)->toBeTrue()
        ->and($ledger)->toHaveCount(1)
        ->and((float) $ledger->first()->quantity_in)->toBe(200.0)
        ->and((float) $ledger->first()->transaction_value)->toBe(5000.0);

    Livewire::actingAs($this->director)
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertFormSet([
            'current_finished_stock_cases' => 10,
            'opening_stock_cases' => 10,
        ])
        ->assertSee('Cases');
});
