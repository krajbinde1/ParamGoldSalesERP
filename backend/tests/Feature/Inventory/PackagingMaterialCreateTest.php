<?php

use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Filament\Resources\PackagingMaterials\Pages\CreatePackagingMaterial;
use App\Filament\Resources\PackagingMaterials\Pages\EditPackagingMaterial;
use App\Filament\Resources\PackagingMaterials\Schemas\PackagingMaterialForm;
use App\Models\PackagingMaterial;
use App\Models\PackagingMaterialInward;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\PackagingMaterialCreateService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->director = User::query()->create([
        'name' => 'PK Create Director',
        'email' => 'pk.create.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
});

it('creates a packaging material without stock when opening quantity is zero', function (): void {
    $material = app(PackagingMaterialCreateService::class)->create(
        materialData: [
            'packaging_name' => 'Zero Opening Carton',
            'unit' => 'Nos',
            'minimum_stock' => 5,
            'batch_tracking_enabled' => false,
            'expiry_tracking_enabled' => false,
            'status' => true,
            'remarks' => null,
        ],
        opening: [
            'quantity' => 0,
            'value' => 0,
        ],
        user: $this->director,
    );

    expect((float) $material->current_stock)->toBe(0.0)
        ->and((float) $material->opening_stock)->toBe(0.0)
        ->and((float) $material->average_rate)->toBe(0.0)
        ->and(PackagingMaterialInward::query()->count())->toBe(0)
        ->and(StockLedger::query()->where('packaging_material_id', $material->id)->count())->toBe(0);
});

it('creates packaging material with opening stock ledger and inventory update without creating an inward', function (): void {
    $beforeInwardCount = PackagingMaterialInward::query()->count();
    $beforeRawLedgerCount = StockLedger::query()
        ->where('item_type', StockItemType::RawMaterial->value)
        ->count();

    // Value 2411.8 @ qty 100 → Effective Rate 24.118 (same MaterialInwardCosting path).
    $material = app(PackagingMaterialCreateService::class)->create(
        materialData: [
            'packaging_name' => 'Opening Cartons',
            'unit' => 'Nos',
            'minimum_stock' => 10,
            'batch_tracking_enabled' => true,
            'expiry_tracking_enabled' => false,
            'status' => true,
            'remarks' => 'Master remarks',
        ],
        opening: [
            'quantity' => 100,
            'value' => 2411.8,
            'date' => now('Asia/Kolkata')->toDateString(),
            'remarks' => 'Initial opening',
        ],
        user: $this->director,
    );

    $material->refresh();

    expect((float) $material->current_stock)->toBe(100.0)
        ->and((float) $material->opening_stock)->toBe(100.0)
        ->and((float) $material->average_rate)->toBe(24.118)
        ->and((float) $material->purchase_rate)->toBe(24.118)
        ->and((float) $material->current_stock_value)->toBe(2411.8)
        ->and($material->batch_tracking_enabled)->toBeTrue()
        ->and(PackagingMaterialInward::query()->count())->toBe($beforeInwardCount);

    $ledger = StockLedger::query()
        ->where('packaging_material_id', $material->id)
        ->where('transaction_type', StockTransactionType::OpeningStock->value)
        ->first();

    expect($ledger)->not->toBeNull()
        ->and($ledger->item_type)->toBe(StockItemType::PackagingMaterial)
        ->and($ledger->raw_material_id)->toBeNull()
        ->and((float) $ledger->quantity_in)->toBe(100.0)
        ->and($ledger->remarks)->toBe('Initial opening')
        ->and($ledger->reference_type)->toBe(PackagingMaterial::class)
        ->and((int) $ledger->reference_id)->toBe($material->id)
        ->and($ledger->reference_number)->toBe($material->packaging_code)
        ->and(StockLedger::query()->where('item_type', StockItemType::RawMaterial->value)->count())
        ->toBe($beforeRawLedgerCount);
});

it('rejects opening stock with quantity but zero value', function (): void {
    expect(fn () => app(PackagingMaterialCreateService::class)->create(
        materialData: [
            'packaging_name' => 'Invalid Opening Pack',
            'unit' => 'Nos',
            'minimum_stock' => 0,
            'status' => true,
        ],
        opening: [
            'quantity' => 10,
            'value' => 0,
        ],
        user: $this->director,
    ))->toThrow(ValidationException::class);

    expect(PackagingMaterial::query()->where('packaging_name', 'Invalid Opening Pack')->exists())->toBeFalse();
});

it('rejects opening stock value when quantity is zero', function (): void {
    expect(fn () => app(PackagingMaterialCreateService::class)->create(
        materialData: [
            'packaging_name' => 'Value Without Qty Pack',
            'unit' => 'Nos',
            'minimum_stock' => 0,
            'status' => true,
        ],
        opening: [
            'quantity' => 0,
            'value' => 500,
        ],
        user: $this->director,
    ))->toThrow(ValidationException::class);

    expect(PackagingMaterial::query()->where('packaging_name', 'Value Without Qty Pack')->exists())->toBeFalse();
});

it('still accepts legacy purchase_rate opening payload for Effective Rate costing', function (): void {
    $material = app(PackagingMaterialCreateService::class)->create(
        materialData: [
            'packaging_name' => 'Legacy Opening Pack',
            'unit' => 'Nos',
            'minimum_stock' => 0,
            'status' => true,
        ],
        opening: [
            'quantity' => 100,
            'purchase_rate' => 20,
            'gst_percentage' => 18,
            'freight' => 40,
            'other_charges' => 10,
            'date' => now('Asia/Kolkata')->toDateString(),
        ],
        user: $this->director,
    );

    // Taxable = 100*20 + 10 = 2010 (freight NOT taxable); GST = 361.8; Value = 2411.8; Eff = 24.118
    expect((float) $material->average_rate)->toBe(24.118)
        ->and((float) $material->current_stock_value)->toBe(2411.8);
});

it('renders the simplified Opening Stock section on the Create Packaging Material page', function (): void {
    $this->actingAs($this->director);

    Livewire::test(CreatePackagingMaterial::class)
        ->assertSuccessful()
        ->assertSee('Material Details')
        ->assertSee('Opening Stock')
        ->assertSee('Opening Stock Quantity')
        ->assertSee('Opening Stock Value')
        ->assertSee('Effective Rate')
        ->assertSee('Opening Date')
        ->assertDontSee('Purchase Rate')
        ->assertDontSee('GST %')
        ->assertDontSee('Freight')
        ->assertDontSee('Other Charges')
        ->assertDontSee('Opening Remarks')
        ->assertSee('Create')
        ->assertSee('Cancel')
        ->assertDontSee('Save Draft')
        ->assertDontSee('Post Inward');
});

it('calculates opening stock value as quantity times effective rate on create', function (): void {
    expect(PackagingMaterialForm::openingStockValue(900, 5.20))->toBe(4680.0)
        ->and(PackagingMaterialForm::openingStockValue(0, 5.20))->toBe(0.0);

    $this->actingAs($this->director);

    $name = 'Rate Carton '.uniqid();

    Livewire::test(CreatePackagingMaterial::class)
        ->fillForm([
            'packaging_name' => $name,
            'unit' => 'Nos',
            'minimum_stock' => 0,
            'status' => true,
            'opening_stock_quantity' => 900,
            'opening_effective_rate' => 5.20,
            'opening_date' => now('Asia/Kolkata')->toDateString(),
        ])
        ->assertSet('data.opening_stock_value', 4680)
        ->call('create')
        ->assertHasNoFormErrors();

    $material = PackagingMaterial::query()->where('packaging_name', $name)->first();

    expect($material)->not->toBeNull()
        ->and((float) $material->opening_stock)->toBe(900.0)
        ->and((float) $material->current_stock)->toBe(900.0)
        ->and((float) $material->current_stock_value)->toBe(4680.0)
        ->and((float) $material->purchase_rate)->toBe(5.2)
        ->and((float) $material->average_rate)->toBe(5.2);

    $ledger = StockLedger::query()
        ->where('packaging_material_id', $material->id)
        ->where('transaction_type', StockTransactionType::OpeningStock->value)
        ->first();

    expect($ledger)->not->toBeNull()
        ->and((float) $ledger->quantity_in)->toBe(900.0)
        ->and((float) $ledger->transaction_value)->toBe(4680.0)
        ->and((float) $ledger->rate)->toBe(5.2);
});

it('recalculates opening stock value from effective rate on edit', function (): void {
    $material = app(PackagingMaterialCreateService::class)->create(
        materialData: [
            'packaging_name' => 'Edit Rate Pack '.uniqid(),
            'unit' => 'Nos',
            'minimum_stock' => 0,
            'status' => true,
        ],
        opening: [
            'quantity' => 100,
            'value' => 2000,
            'date' => now('Asia/Kolkata')->toDateString(),
        ],
        user: $this->director,
    );

    $this->actingAs($this->director);

    Livewire::test(EditPackagingMaterial::class, ['record' => $material->getKey()])
        ->fillForm([
            'opening_stock_quantity' => 900,
            'opening_effective_rate' => 5.20,
            'opening_date' => now('Asia/Kolkata')->toDateString(),
        ])
        ->assertSet('data.opening_stock_value', 4680)
        ->call('save')
        ->assertHasNoFormErrors();

    $material->refresh();

    expect((float) $material->opening_stock)->toBe(900.0)
        ->and((float) $material->current_stock)->toBe(900.0)
        ->and((float) $material->current_stock_value)->toBe(4680.0)
        ->and((float) $material->purchase_rate)->toBe(5.2);

    $ledger = StockLedger::query()
        ->where('packaging_material_id', $material->id)
        ->where('transaction_type', StockTransactionType::OpeningStock->value)
        ->first();

    expect((float) $ledger->transaction_value)->toBe(4680.0)
        ->and((float) $ledger->rate)->toBe(5.2);
});
