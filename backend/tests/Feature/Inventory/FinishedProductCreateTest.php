<?php

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Filament\Resources\FinishedProducts\FinishedProductResource;
use App\Filament\Resources\FinishedProducts\Pages\CreateFinishedProduct;
use App\Filament\Resources\FinishedProducts\Pages\EditFinishedProduct;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\FinishedProduct;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\RawMaterial;
use App\Models\RawMaterialInward;
use App\Models\SemiFinishedMaterial;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\FinishedProductCreateService;
use App\Services\Inventory\FinishedProductOpeningStockCalculator;
use App\Services\Inventory\InventoryReportService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->director = User::query()->create([
        'name' => 'FG Create Director',
        'email' => 'fg.create.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
});

function makeSalesProduct(string $name, array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'product_name' => $name,
        'category' => 'General',
        'uom' => 'Kg',
        'dealer_price' => 100,
        'gst_percentage' => 18,
        'status' => true,
        'manufacturing_enabled' => false,
        'current_finished_stock' => 0,
        'opening_finished_stock' => 0,
    ], $overrides));
}

function makeFinishedOpeningBom(Product $product, float $costPerNos = 25): Bom
{
    $raw = RawMaterial::query()->create([
        'material_name' => 'FG Opening RM '.$product->id,
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

it('enables inventory on an existing sales product without stock when opening quantity is zero', function (): void {
    $sales = makeSalesProduct('Zero Opening FG');
    $beforeCount = Product::query()->count();

    $product = app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $sales->id,
            'unit' => 'Kg',
            'minimum_finished_stock' => 5,
            'status' => true,
            'remarks' => null,
        ],
        opening: [
            'quantity' => 0,
            'value' => 0,
        ],
        user: $this->director,
    );

    $product->load('finishedProduct');

    expect($product->id)->toBe($sales->id)
        ->and(Product::query()->count())->toBe($beforeCount)
        ->and($product->manufacturing_enabled)->toBeTrue()
        ->and($product->finishedProduct)->not->toBeNull()
        ->and($product->finishedProduct->finished_product_code)->toStartWith('FP')
        ->and(FinishedProduct::query()->where('product_id', $product->id)->count())->toBe(1)
        ->and((float) $product->current_finished_stock)->toBe(0.0)
        ->and((float) $product->opening_finished_stock)->toBe(0.0)
        ->and((float) $product->weighted_average_cost)->toBe(0.0)
        ->and((float) $product->current_stock_value)->toBe(0.0)
        ->and(ProductionBatch::query()->count())->toBe(0)
        ->and(RawMaterialInward::query()->count())->toBe(0)
        ->and(StockLedger::query()->where('product_id', $product->id)->count())->toBe(0);
});

it('posts opening stock ledger on existing sales product without production or inward', function (): void {
    $sales = makeSalesProduct('Opening FG Premix');
    $beforeCount = Product::query()->count();
    $beforeProductionCount = ProductionBatch::query()->count();
    $beforeInwardCount = RawMaterialInward::query()->count();
    $beforeRawLedgerCount = StockLedger::query()
        ->where('item_type', StockItemType::RawMaterial->value)
        ->count();
    $beforePackagingLedgerCount = StockLedger::query()
        ->where('item_type', StockItemType::PackagingMaterial->value)
        ->count();
    $beforeSemiLedgerCount = StockLedger::query()
        ->where('item_type', StockItemType::SemiFinished->value)
        ->count();

    // Value 2411.8 @ qty 100 → Effective Rate 24.118
    $product = app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $sales->id,
            'unit' => 'Kg',
            'minimum_finished_stock' => 10,
            'status' => true,
            'remarks' => 'Master remarks',
            'batch_tracking_enabled' => true,
            'expiry_tracking_enabled' => false,
        ],
        opening: [
            'quantity' => 100,
            'value' => 2411.8,
            'date' => now('Asia/Kolkata')->toDateString(),
            'remarks' => 'Initial opening',
        ],
        user: $this->director,
    );

    $product->refresh();

    expect($product->id)->toBe($sales->id)
        ->and(Product::query()->count())->toBe($beforeCount)
        ->and($product->manufacturing_enabled)->toBeTrue()
        ->and((float) $product->current_finished_stock)->toBe(100.0)
        ->and((float) $product->opening_finished_stock)->toBe(100.0)
        ->and((float) $product->weighted_average_cost)->toBe(24.118)
        ->and((float) $product->current_stock_value)->toBe(2411.8)
        ->and(ProductionBatch::query()->count())->toBe($beforeProductionCount)
        ->and(RawMaterialInward::query()->count())->toBe($beforeInwardCount);

    $ledger = StockLedger::query()
        ->where('product_id', $product->id)
        ->where('transaction_type', StockTransactionType::OpeningStock->value)
        ->first();

    expect($ledger)->not->toBeNull()
        ->and($ledger->item_type)->toBe(StockItemType::FinishedProduct)
        ->and($ledger->raw_material_id)->toBeNull()
        ->and($ledger->packaging_material_id)->toBeNull()
        ->and($ledger->semi_finished_id)->toBeNull()
        ->and((int) $ledger->product_id)->toBe($product->id)
        ->and((float) $ledger->quantity_in)->toBe(100.0)
        ->and($ledger->remarks)->toBe('Initial opening')
        ->and($ledger->reference_type)->toBe(Product::class)
        ->and((int) $ledger->reference_id)->toBe($product->id)
        ->and($ledger->reference_number)->toBe($product->product_code)
        ->and(StockLedger::query()->where('item_type', StockItemType::RawMaterial->value)->count())
        ->toBe($beforeRawLedgerCount)
        ->and(StockLedger::query()->where('item_type', StockItemType::PackagingMaterial->value)->count())
        ->toBe($beforePackagingLedgerCount)
        ->and(StockLedger::query()->where('item_type', StockItemType::SemiFinished->value)->count())
        ->toBe($beforeSemiLedgerCount);

    $report = app(InventoryReportService::class)->build([
        'inventory_type' => InventoryReportService::TYPE_FINISHED_PRODUCT,
    ]);
    $fgRow = $report->query->get()->first(
        fn ($row): bool => (string) ($row->code ?? '') === $product->product_code
    );

    expect($fgRow)->not->toBeNull()
        ->and((float) $fgRow->current_stock)->toBe(100.0)
        ->and((float) $fgRow->stock_value)->toBe(2411.8);
});

it('requires an existing sales product and never creates a new product row', function (): void {
    $beforeCount = Product::query()->count();

    expect(fn () => app(FinishedProductCreateService::class)->create(
        productData: [
            'product_name' => 'Should Not Create',
            'unit' => 'Kg',
            'minimum_finished_stock' => 0,
            'status' => true,
        ],
        opening: ['quantity' => 0, 'value' => 0],
        user: $this->director,
    ))->toThrow(ValidationException::class);

    expect(Product::query()->count())->toBe($beforeCount)
        ->and(Product::query()->where('product_name', 'Should Not Create')->exists())->toBeFalse();
});

it('links an existing sales product with opening stock without changing sales pricing', function (): void {
    $salesProduct = makeSalesProduct('Sales Linked FG', [
        'uom' => 'Piece',
        'dealer_price' => 150,
        'gst_percentage' => 18,
    ]);

    $dealerPriceBefore = (float) $salesProduct->dealer_price;
    $gstBefore = (float) $salesProduct->gst_percentage;
    $beforeCount = Product::query()->count();

    $product = app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $salesProduct->id,
            'unit' => 'Nos',
            'minimum_finished_stock' => 2,
            'status' => true,
            'batch_tracking_enabled' => true,
        ],
        opening: [
            'quantity' => 50,
            'value' => 1000,
            'date' => now('Asia/Kolkata')->toDateString(),
        ],
        user: $this->director,
    );

    $product->refresh();
    $product->load('finishedProduct');

    expect($product->id)->toBe($salesProduct->id)
        ->and(Product::query()->count())->toBe($beforeCount)
        ->and($product->manufacturing_enabled)->toBeTrue()
        ->and($product->production_unit)->toBe('Nos')
        ->and($product->product_name)->toBe('Sales Linked FG')
        ->and($product->finishedProduct)->not->toBeNull()
        ->and($product->finishedProduct->finished_product_code)->toStartWith('FP')
        ->and($product->finishedProduct->unit)->toBe('Nos')
        ->and((float) $product->current_finished_stock)->toBe(50.0)
        ->and((float) $product->weighted_average_cost)->toBe(20.0)
        ->and((float) $product->dealer_price)->toBe($dealerPriceBefore)
        ->and((float) $product->gst_percentage)->toBe($gstBefore)
        ->and(Product::query()->where('product_name', 'Sales Linked FG')->count())->toBe(1)
        ->and(FinishedProduct::query()->where('product_id', $product->id)->count())->toBe(1);
});

it('rejects duplicate opening stock on the same sales product', function (): void {
    $sales = makeSalesProduct('Already FG');

    app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $sales->id,
            'unit' => 'Kg',
            'minimum_finished_stock' => 0,
            'status' => true,
        ],
        opening: [
            'quantity' => 10,
            'value' => 100,
            'date' => now('Asia/Kolkata')->toDateString(),
        ],
        user: $this->director,
    );

    expect(fn () => app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $sales->id,
            'unit' => 'Kg',
            'minimum_finished_stock' => 0,
            'status' => true,
        ],
        opening: [
            'quantity' => 5,
            'value' => 50,
            'date' => now('Asia/Kolkata')->toDateString(),
        ],
        user: $this->director,
    ))->toThrow(ValidationException::class);
});

it('rejects opening stock with quantity but zero value', function (): void {
    $sales = makeSalesProduct('Invalid Opening FG');
    $beforeCount = Product::query()->count();

    expect(fn () => app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $sales->id,
            'unit' => 'Kg',
            'minimum_finished_stock' => 0,
            'status' => true,
        ],
        opening: [
            'quantity' => 10,
            'value' => 0,
        ],
        user: $this->director,
    ))->toThrow(ValidationException::class);

    expect(Product::query()->count())->toBe($beforeCount);
});

it('rejects opening stock value when quantity is zero', function (): void {
    $sales = makeSalesProduct('Value Without Qty FG');
    $beforeCount = Product::query()->count();

    expect(fn () => app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $sales->id,
            'unit' => 'Kg',
            'minimum_finished_stock' => 0,
            'status' => true,
        ],
        opening: [
            'quantity' => 0,
            'value' => 500,
        ],
        user: $this->director,
    ))->toThrow(ValidationException::class);

    expect(Product::query()->count())->toBe($beforeCount);
});

it('does not re-post opening stock ledger when editing inventory details', function (): void {
    $sales = makeSalesProduct('Edit FG');

    $product = app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $sales->id,
            'unit' => 'Kg',
            'minimum_finished_stock' => 5,
            'status' => true,
        ],
        opening: [
            'quantity' => 50,
            'value' => 1000,
            'date' => now('Asia/Kolkata')->toDateString(),
        ],
        user: $this->director,
    );

    $ledgerCountBefore = StockLedger::query()
        ->where('product_id', $product->id)
        ->where('transaction_type', StockTransactionType::OpeningStock->value)
        ->count();

    $dealerPriceBefore = (float) $product->dealer_price;
    $nameBefore = $product->product_name;

    $this->actingAs($this->director);

    Livewire::test(EditFinishedProduct::class, ['record' => $product->getKey()])
        ->assertSuccessful()
        ->fillForm([
            'minimum_finished_stock' => 8,
            'remarks' => 'Updated details only',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $product->refresh();

    expect($product->product_name)->toBe($nameBefore)
        ->and((float) $product->minimum_finished_stock)->toBe(8.0)
        ->and((float) $product->opening_finished_stock)->toBe(50.0)
        ->and((float) $product->current_finished_stock)->toBe(50.0)
        ->and((float) $product->dealer_price)->toBe($dealerPriceBefore)
        ->and(StockLedger::query()
            ->where('product_id', $product->id)
            ->where('transaction_type', StockTransactionType::OpeningStock->value)
            ->count())->toBe($ledgerCountBefore);
});

it('does not create raw packaging or semi-finished masters when enabling finished goods inventory', function (): void {
    $sales = makeSalesProduct('Isolation FG');
    $rawBefore = RawMaterial::query()->count();
    $packBefore = PackagingMaterial::query()->count();
    $sfBefore = SemiFinishedMaterial::query()->count();

    app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $sales->id,
            'unit' => 'Kg',
            'minimum_finished_stock' => 0,
            'status' => true,
        ],
        opening: [
            'quantity' => 10,
            'value' => 200,
            'date' => now('Asia/Kolkata')->toDateString(),
        ],
        user: $this->director,
    );

    expect(RawMaterial::query()->count())->toBe($rawBefore)
        ->and(PackagingMaterial::query()->count())->toBe($packBefore)
        ->and(SemiFinishedMaterial::query()->count())->toBe($sfBefore);
});

it('renders Set Opening Stock with sales product picker only', function (): void {
    $this->actingAs($this->director);

    Livewire::test(CreateFinishedProduct::class)
        ->assertSuccessful()
        ->assertSee('Set Opening Stock')
        ->assertSee('Sales Product')
        ->assertSee('Opening Stock')
        ->assertSee('Opening Stock (Cases)')
        ->assertSee('Opening Qty (Nos)')
        ->assertSee('Average Cost/Nos')
        ->assertSee('Opening Stock Value')
        ->assertSee('As On Date')
        ->assertDontSee('Purchase Rate')
        ->assertDontSee('GST %')
        ->assertDontSee('Create & create another')
        ->assertSee('Save')
        ->assertSee('Cancel');
});

it('lists all sales products in Finished Goods Inventory', function (): void {
    makeSalesProduct('List FG A');
    makeSalesProduct('List FG B');

    $expected = Product::query()->count();

    expect(FinishedProductResource::getEloquentQuery()->count())->toBe($expected);
});

it('posts opening stock from cases using active bom estimated cost and includes it in available stock', function (): void {
    $sales = makeSalesProduct('Cases Opening FG', [
        'uom' => 'Nos',
        'nos_per_case' => 20,
        'production_unit' => 'Nos',
    ]);
    makeFinishedOpeningBom($sales, 25);

    $this->actingAs($this->director);

    Livewire::test(CreateFinishedProduct::class)
        ->fillForm([
            'linked_product_id' => $sales->id,
            'unit' => 'Nos',
            'minimum_finished_stock' => 0,
            'opening_stock_cases' => 10,
            'opening_date' => now('Asia/Kolkata')->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $sales->refresh();
    $ledger = StockLedger::query()
        ->where('product_id', $sales->id)
        ->where('transaction_type', StockTransactionType::OpeningStock->value)
        ->get();

    expect((float) $sales->opening_finished_stock)->toBe(200.0)
        ->and((float) $sales->current_finished_stock)->toBe(200.0)
        ->and((float) $sales->weighted_average_cost)->toBe(25.0)
        ->and((float) $sales->current_stock_value)->toBe(5000.0)
        ->and($ledger)->toHaveCount(1)
        ->and((float) $ledger->first()->quantity_in)->toBe(200.0)
        ->and((float) $ledger->first()->transaction_value)->toBe(5000.0);
});

it('does not save opening stock when the product has no active bom', function (): void {
    $sales = makeSalesProduct('No Bom Opening FG', [
        'uom' => 'Nos',
        'nos_per_case' => 20,
    ]);

    $this->actingAs($this->director);

    Livewire::test(CreateFinishedProduct::class)
        ->fillForm([
            'linked_product_id' => $sales->id,
            'unit' => 'Nos',
            'opening_stock_cases' => 10,
            'opening_date' => now('Asia/Kolkata')->toDateString(),
        ])
        ->call('create')
        ->assertHasFormErrors(['opening_stock_cases']);

    $sales->refresh();

    expect((float) $sales->opening_finished_stock)->toBe(0.0)
        ->and((float) $sales->current_finished_stock)->toBe(0.0)
        ->and(StockLedger::query()->where('product_id', $sales->id)->count())->toBe(0);
});

it('does not duplicate the finished product opening ledger when create is repeated', function (): void {
    $sales = makeSalesProduct('Dup Opening FG', [
        'uom' => 'Nos',
        'nos_per_case' => 20,
    ]);
    makeFinishedOpeningBom($sales, 25);
    $resolved = app(FinishedProductOpeningStockCalculator::class)->resolveForSave(
        $sales,
        10,
        now('Asia/Kolkata')->toDateString(),
    );

    app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $sales->id,
            'unit' => 'Nos',
            'minimum_finished_stock' => 0,
            'status' => true,
        ],
        opening: $resolved,
        user: $this->director,
    );

    expect(fn () => app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $sales->id,
            'unit' => 'Nos',
            'minimum_finished_stock' => 0,
            'status' => true,
        ],
        opening: $resolved,
        user: $this->director,
    ))->toThrow(ValidationException::class);

    expect(StockLedger::query()
        ->where('product_id', $sales->id)
        ->where('transaction_type', StockTransactionType::OpeningStock->value)
        ->count())->toBe(1)
        ->and((float) $sales->fresh()->current_finished_stock)->toBe(200.0);
});
