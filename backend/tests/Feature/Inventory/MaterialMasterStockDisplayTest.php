<?php

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Filament\Resources\FinishedProducts\Pages\EditFinishedProduct;
use App\Filament\Resources\FinishedProducts\Pages\ListFinishedProducts;
use App\Filament\Resources\FinishedProducts\Pages\ViewFinishedProduct;
use App\Filament\Resources\PackagingMaterials\Pages\EditPackagingMaterial;
use App\Filament\Resources\PackagingMaterials\Pages\ListPackagingMaterials;
use App\Filament\Resources\PackagingMaterials\Pages\ViewPackagingMaterial;
use App\Filament\Resources\RawMaterials\Pages\EditRawMaterial;
use App\Filament\Resources\RawMaterials\Pages\ListRawMaterials;
use App\Filament\Resources\RawMaterials\Pages\ViewRawMaterial;
use App\Filament\Resources\SemiFinishedMaterials\Pages\EditSemiFinishedMaterial;
use App\Filament\Resources\SemiFinishedMaterials\Pages\ListSemiFinishedMaterials;
use App\Filament\Resources\SemiFinishedMaterials\Pages\ViewSemiFinishedMaterial;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\FinishedProductCreateService;
use App\Services\Inventory\MaterialOpeningStockSyncService;
use App\Services\Inventory\PackagingMaterialCreateService;
use App\Services\Inventory\RawMaterialCreateService;
use App\Services\Inventory\SemiFinishedMaterialCreateService;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->director = User::query()->create([
        'name' => 'Material Master Director',
        'email' => 'mm.stock.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
});

function masterStockOpening(): array
{
    return [
        'quantity' => 100,
        'value' => 2000,
        'date' => now('Asia/Kolkata')->toDateString(),
    ];
}

function postLaterRawMovement($material, User $user, float $qtyIn = 25): void
{
    app(StockLedgerService::class)->postRawMaterialMovement(
        $material->fresh(),
        $qtyIn,
        0,
        (float) $material->average_rate,
        [
            'transaction_date' => now('Asia/Kolkata')->toDateString(),
            'transaction_type' => StockTransactionType::RawMaterialInward,
            'remarks' => 'Later inward',
        ],
        $user,
    );
}

it('lists available stock and live stock value for all four material masters after later movements', function (): void {
    $raw = app(RawMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Live Raw '.uniqid(),
            'unit' => 'Kg',
            'minimum_stock' => 1,
            'status' => true,
        ],
        opening: masterStockOpening(),
        user: $this->director,
    );
    postLaterRawMovement($raw, $this->director);
    $raw->refresh();

    $packaging = app(PackagingMaterialCreateService::class)->create(
        materialData: [
            'packaging_name' => 'Live Pack '.uniqid(),
            'unit' => 'Nos',
            'minimum_stock' => 1,
            'status' => true,
        ],
        opening: masterStockOpening(),
        user: $this->director,
    );
    app(StockLedgerService::class)->postPackagingMaterialMovement(
        $packaging->fresh(),
        25,
        0,
        (float) $packaging->average_rate,
        [
            'transaction_date' => now('Asia/Kolkata')->toDateString(),
            'transaction_type' => StockTransactionType::PackagingMaterialInward,
            'remarks' => 'Later inward',
        ],
        $this->director,
    );
    $packaging->refresh();

    $sf = app(SemiFinishedMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Live SF '.uniqid(),
            'unit' => 'Kg',
            'minimum_stock' => 1,
            'status' => true,
        ],
        opening: masterStockOpening(),
        user: $this->director,
    );
    app(StockLedgerService::class)->postSemiFinishedMovement(
        $sf->fresh(),
        25,
        0,
        (float) $sf->average_production_cost,
        [
            'transaction_date' => now('Asia/Kolkata')->toDateString(),
            'transaction_type' => StockTransactionType::SemiFinishedProduction,
            'remarks' => 'Later production',
        ],
        $this->director,
    );
    $sf->refresh();

    $sales = Product::query()->create([
        'product_name' => 'Live FG '.uniqid(),
        'category' => 'General',
        'uom' => 'Kg',
        'dealer_price' => 100,
        'gst_percentage' => 18,
        'status' => true,
        'manufacturing_enabled' => false,
        'current_finished_stock' => 0,
        'opening_finished_stock' => 0,
    ]);
    $fg = app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $sales->id,
            'unit' => 'Kg',
            'minimum_finished_stock' => 1,
            'status' => true,
        ],
        opening: masterStockOpening(),
        user: $this->director,
    );
    app(StockLedgerService::class)->postFinishedProductMovement(
        $fg->fresh(),
        25,
        0,
        (float) $fg->weighted_average_cost,
        [
            'transaction_date' => now('Asia/Kolkata')->toDateString(),
            'transaction_type' => StockTransactionType::ProductionOutput,
            'remarks' => 'Later production',
        ],
        $this->director,
    );
    $fg->refresh();

    expect((float) $raw->current_stock)->toBe(125.0)
        ->and((float) $raw->opening_stock)->toBe(100.0)
        ->and((float) $raw->current_stock_value)->not->toBe(2000.0)
        ->and((float) $packaging->current_stock)->toBe(125.0)
        ->and((float) $packaging->current_stock_value)->not->toBe(2000.0)
        ->and((float) $sf->current_stock)->toBe(125.0)
        ->and((float) $sf->current_stock_value)->not->toBe(2000.0)
        ->and((float) $fg->current_finished_stock)->toBe(125.0)
        ->and((float) $fg->opening_finished_stock)->toBe(100.0)
        ->and((float) $fg->current_stock_value)->not->toBe(2000.0);

    $this->actingAs($this->director);

    Livewire::test(ListRawMaterials::class)
        ->assertSuccessful()
        ->assertSee('Available Stock')
        ->assertSee('Average Stock Rate')
        ->assertSee('Stock Value')
        ->assertSee($raw->material_name);

    Livewire::test(ListPackagingMaterials::class)
        ->assertSuccessful()
        ->assertSee('Available Stock')
        ->assertSee('Average Stock Rate')
        ->assertSee('Stock Value')
        ->assertSee($packaging->packaging_name);

    Livewire::test(ListSemiFinishedMaterials::class)
        ->assertSuccessful()
        ->assertSee('Available Stock')
        ->assertSee('Stock Value')
        ->assertSee($sf->material_name);

    Livewire::test(ListFinishedProducts::class)
        ->assertSuccessful()
        ->assertSee('Available Stock')
        ->assertSee('Stock Value')
        ->assertSee($fg->product_name);

    Livewire::test(ViewRawMaterial::class, ['record' => $raw->getKey()])
        ->assertSuccessful()
        ->assertSee('Available Stock')
        ->assertSee('Average Stock Rate')
        ->assertSee('Stock Value');

    Livewire::test(ViewPackagingMaterial::class, ['record' => $packaging->getKey()])
        ->assertSuccessful()
        ->assertSee('Available Stock')
        ->assertSee('Average Stock Rate')
        ->assertSee('Stock Value');

    Livewire::test(ViewSemiFinishedMaterial::class, ['record' => $sf->getKey()])
        ->assertSuccessful()
        ->assertSee('Available Stock')
        ->assertSee('Stock Value');

    Livewire::test(ViewFinishedProduct::class, ['record' => $fg->getKey()])
        ->assertSuccessful()
        ->assertSee('Available Stock')
        ->assertSee('Stock Value');
});

it('lets edit post opening stock when the material has none', function (): void {
    $material = app(RawMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'No Opening Raw '.uniqid(),
            'unit' => 'Kg',
            'minimum_stock' => 0,
            'status' => true,
        ],
        opening: ['quantity' => 0, 'value' => 0],
        user: $this->director,
    );

    $this->actingAs($this->director);

    Livewire::test(EditRawMaterial::class, ['record' => $material->getKey()])
        ->assertSuccessful()
        ->assertSee('Opening Stock Quantity')
        ->assertSee('Opening Stock Value')
        ->assertSee('Opening Date')
        ->assertSee('Available Stock')
        ->fillForm([
            'opening_stock_quantity' => 40,
            'opening_stock_value' => 800,
            'opening_date' => now('Asia/Kolkata')->toDateString(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $material->refresh();

    expect((float) $material->opening_stock)->toBe(40.0)
        ->and((float) $material->current_stock)->toBe(40.0)
        ->and((float) $material->current_stock_value)->toBe(800.0)
        ->and(StockLedger::query()
            ->where('raw_material_id', $material->id)
            ->where('transaction_type', StockTransactionType::OpeningStock->value)
            ->count())->toBe(1);
});

it('lets edit rewrite opening stock when no later movements exist', function (): void {
    $material = app(PackagingMaterialCreateService::class)->create(
        materialData: [
            'packaging_name' => 'Rewrite Pack '.uniqid(),
            'unit' => 'Nos',
            'minimum_stock' => 0,
            'status' => true,
        ],
        opening: masterStockOpening(),
        user: $this->director,
    );

    $this->actingAs($this->director);

    Livewire::test(EditPackagingMaterial::class, ['record' => $material->getKey()])
        ->fillForm([
            'opening_stock_quantity' => 80,
            'opening_stock_value' => 1600,
            'opening_date' => now('Asia/Kolkata')->toDateString(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $material->refresh();
    $ledger = StockLedger::query()
        ->where('packaging_material_id', $material->id)
        ->where('transaction_type', StockTransactionType::OpeningStock->value)
        ->first();

    expect((float) $material->opening_stock)->toBe(80.0)
        ->and((float) $material->current_stock)->toBe(80.0)
        ->and((float) $material->current_stock_value)->toBe(1600.0)
        ->and(StockLedger::query()
            ->where('packaging_material_id', $material->id)
            ->where('transaction_type', StockTransactionType::OpeningStock->value)
            ->count())->toBe(1)
        ->and((float) $ledger->quantity_in)->toBe(80.0)
        ->and((float) $ledger->transaction_value)->toBe(1600.0);
});

it('rejects opening quantity changes after later inventory movements', function (): void {
    $material = app(SemiFinishedMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Locked SF '.uniqid(),
            'unit' => 'Kg',
            'minimum_stock' => 0,
            'status' => true,
        ],
        opening: masterStockOpening(),
        user: $this->director,
    );
    app(StockLedgerService::class)->postSemiFinishedMovement(
        $material->fresh(),
        10,
        0,
        (float) $material->average_production_cost,
        [
            'transaction_date' => now('Asia/Kolkata')->toDateString(),
            'transaction_type' => StockTransactionType::SemiFinishedProduction,
            'remarks' => 'Later production',
        ],
        $this->director,
    );

    $stockBefore = (float) $material->fresh()->current_stock;
    $valueBefore = (float) $material->fresh()->current_stock_value;

    $this->actingAs($this->director);

    Livewire::test(EditSemiFinishedMaterial::class, ['record' => $material->getKey()])
        ->fillForm([
            'opening_stock_quantity' => 200,
            'opening_stock_value' => 4000,
        ])
        ->call('save')
        ->assertHasFormErrors(['opening_stock_quantity']);

    $material->refresh();

    expect((float) $material->opening_stock)->toBe(100.0)
        ->and((float) $material->current_stock)->toBe($stockBefore)
        ->and((float) $material->current_stock_value)->toBe($valueBefore);
});

it('rejects finished goods opening changes after production and keeps live stock', function (): void {
    $sales = Product::query()->create([
        'product_name' => 'Locked FG '.uniqid(),
        'category' => 'General',
        'uom' => 'Kg',
        'dealer_price' => 100,
        'gst_percentage' => 18,
        'status' => true,
        'manufacturing_enabled' => false,
        'current_finished_stock' => 0,
        'opening_finished_stock' => 0,
    ]);
    $product = app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $sales->id,
            'unit' => 'Kg',
            'minimum_finished_stock' => 0,
            'status' => true,
        ],
        opening: masterStockOpening(),
        user: $this->director,
    );
    app(StockLedgerService::class)->postFinishedProductMovement(
        $product->fresh(),
        15,
        0,
        (float) $product->weighted_average_cost,
        [
            'transaction_date' => now('Asia/Kolkata')->toDateString(),
            'transaction_type' => StockTransactionType::ProductionOutput,
            'remarks' => 'Later production',
        ],
        $this->director,
    );

    $this->actingAs($this->director);

    expect(fn () => app(MaterialOpeningStockSyncService::class)->syncFinishedProduct(
        $product->fresh(),
        [
            'quantity' => 50,
            'value' => 900,
            'date' => now('Asia/Kolkata')->toDateString(),
        ],
        $this->director,
    ))->toThrow(ValidationException::class);

    $product->refresh();

    expect((float) $product->opening_finished_stock)->toBe(100.0)
        ->and((float) $product->current_finished_stock)->toBe(115.0)
        ->and((float) $product->current_stock_value)->toBe(2300.0);

    $raw = RawMaterial::query()->create([
        'material_name' => 'Locked FG RM '.$product->id,
        'unit' => 'Nos',
        'purchase_rate' => 20,
        'average_rate' => 20,
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

    Livewire::test(EditFinishedProduct::class, ['record' => $product->getKey()])
        ->assertSee('Opening Stock (Cases)')
        ->assertSee('Available Stock')
        ->fillForm([
            'opening_stock_cases' => 50,
        ])
        ->call('save')
        ->assertHasFormErrors(['opening_stock_quantity']);
});
