<?php

use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Filament\Resources\SemiFinishedMaterials\Pages\CreateSemiFinishedMaterial;
use App\Filament\Resources\SemiFinishedMaterials\Pages\EditSemiFinishedMaterial;
use App\Models\ProductionBatch;
use App\Models\RawMaterialInward;
use App\Models\SemiFinishedMaterial;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\InventoryReportService;
use App\Services\Inventory\SemiFinishedMaterialCreateService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->director = User::query()->create([
        'name' => 'SF Create Director',
        'email' => 'sf.create.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
});

it('creates a semi-finished material without stock when opening quantity is zero', function (): void {
    $material = app(SemiFinishedMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Zero Opening Premix',
            'unit' => 'Kg',
            'minimum_stock' => 5,
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
        ->and((float) $material->average_production_cost)->toBe(0.0)
        ->and((float) $material->current_stock_value)->toBe(0.0)
        ->and(ProductionBatch::query()->count())->toBe(0)
        ->and(RawMaterialInward::query()->count())->toBe(0)
        ->and(StockLedger::query()->where('semi_finished_id', $material->id)->count())->toBe(0);
});

it('creates semi-finished material with opening stock ledger and inventory update without production or inward', function (): void {
    $beforeProductionCount = ProductionBatch::query()->count();
    $beforeInwardCount = RawMaterialInward::query()->count();
    $beforeRawLedgerCount = StockLedger::query()
        ->where('item_type', StockItemType::RawMaterial->value)
        ->count();
    $beforePackagingLedgerCount = StockLedger::query()
        ->where('item_type', StockItemType::PackagingMaterial->value)
        ->count();
    $beforeFinishedLedgerCount = StockLedger::query()
        ->where('item_type', StockItemType::FinishedProduct->value)
        ->count();

    // Value 2411.8 @ qty 100 → Effective Rate 24.118 (same MaterialInwardCosting path).
    $material = app(SemiFinishedMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Opening Premix',
            'unit' => 'Kg',
            'minimum_stock' => 10,
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
        ->and((float) $material->average_production_cost)->toBe(24.118)
        ->and((float) $material->current_stock_value)->toBe(2411.8)
        ->and(ProductionBatch::query()->count())->toBe($beforeProductionCount)
        ->and(RawMaterialInward::query()->count())->toBe($beforeInwardCount);

    $ledger = StockLedger::query()
        ->where('semi_finished_id', $material->id)
        ->where('transaction_type', StockTransactionType::OpeningStock->value)
        ->first();

    expect($ledger)->not->toBeNull()
        ->and($ledger->item_type)->toBe(StockItemType::SemiFinished)
        ->and($ledger->raw_material_id)->toBeNull()
        ->and($ledger->packaging_material_id)->toBeNull()
        ->and($ledger->product_id)->toBeNull()
        ->and((int) $ledger->semi_finished_id)->toBe($material->id)
        ->and((float) $ledger->quantity_in)->toBe(100.0)
        ->and($ledger->remarks)->toBe('Initial opening')
        ->and($ledger->reference_type)->toBe(SemiFinishedMaterial::class)
        ->and((int) $ledger->reference_id)->toBe($material->id)
        ->and($ledger->reference_number)->toBe($material->material_code)
        ->and(StockLedger::query()->where('item_type', StockItemType::RawMaterial->value)->count())
        ->toBe($beforeRawLedgerCount)
        ->and(StockLedger::query()->where('item_type', StockItemType::PackagingMaterial->value)->count())
        ->toBe($beforePackagingLedgerCount)
        ->and(StockLedger::query()->where('item_type', StockItemType::FinishedProduct->value)->count())
        ->toBe($beforeFinishedLedgerCount);

    $report = app(InventoryReportService::class)->build([
        'inventory_type' => InventoryReportService::TYPE_SEMI_FINISHED,
    ]);
    $sfRow = $report->query->get()->first(
        fn ($row): bool => (string) ($row->code ?? '') === $material->material_code
    );

    expect($sfRow)->not->toBeNull()
        ->and((float) $sfRow->current_stock)->toBe(100.0)
        ->and((float) $sfRow->stock_value)->toBe(2411.8);
});

it('rejects opening stock with quantity but zero value', function (): void {
    expect(fn () => app(SemiFinishedMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Invalid Opening Premix',
            'unit' => 'Kg',
            'minimum_stock' => 0,
            'status' => true,
        ],
        opening: [
            'quantity' => 10,
            'value' => 0,
        ],
        user: $this->director,
    ))->toThrow(ValidationException::class);

    expect(SemiFinishedMaterial::query()->where('material_name', 'Invalid Opening Premix')->exists())->toBeFalse();
});

it('rejects opening stock value when quantity is zero', function (): void {
    expect(fn () => app(SemiFinishedMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Value Without Qty Premix',
            'unit' => 'Kg',
            'minimum_stock' => 0,
            'status' => true,
        ],
        opening: [
            'quantity' => 0,
            'value' => 500,
        ],
        user: $this->director,
    ))->toThrow(ValidationException::class);

    expect(SemiFinishedMaterial::query()->where('material_name', 'Value Without Qty Premix')->exists())->toBeFalse();
});

it('still accepts legacy purchase_rate opening payload for Effective Rate costing', function (): void {
    $material = app(SemiFinishedMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Legacy Opening Premix',
            'unit' => 'Kg',
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
    expect((float) $material->average_production_cost)->toBe(24.118)
        ->and((float) $material->current_stock_value)->toBe(2411.8);
});

it('does not re-post opening stock ledger when editing material details', function (): void {
    $material = app(SemiFinishedMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Edit Premix',
            'unit' => 'Kg',
            'minimum_stock' => 5,
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
        ->where('semi_finished_id', $material->id)
        ->where('transaction_type', StockTransactionType::OpeningStock->value)
        ->count();

    $this->actingAs($this->director);

    Livewire::test(EditSemiFinishedMaterial::class, ['record' => $material->getKey()])
        ->assertSuccessful()
        ->fillForm([
            'material_name' => 'Edit Premix Updated',
            'minimum_stock' => 8,
            'remarks' => 'Updated details only',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $material->refresh();

    expect($material->material_name)->toBe('Edit Premix Updated')
        ->and((float) $material->minimum_stock)->toBe(8.0)
        ->and((float) $material->opening_stock)->toBe(50.0)
        ->and((float) $material->current_stock)->toBe(50.0)
        ->and(StockLedger::query()
            ->where('semi_finished_id', $material->id)
            ->where('transaction_type', StockTransactionType::OpeningStock->value)
            ->count())->toBe($ledgerCountBefore);
});

it('renders the simplified Opening Stock section on the Create Semi-Finished Material page', function (): void {
    $this->actingAs($this->director);

    Livewire::test(CreateSemiFinishedMaterial::class)
        ->assertSuccessful()
        ->assertSee('Material Details')
        ->assertSee('Opening Stock')
        ->assertSee('Opening Stock Quantity')
        ->assertSee('Opening Stock Value')
        ->assertSee('Effective Rate')
        ->assertSee('Opening Date')
        ->assertDontSee('Avg Production Cost')
        ->assertDontSee('Purchase Rate')
        ->assertDontSee('GST %')
        ->assertDontSee('Freight')
        ->assertDontSee('Other Charges')
        ->assertSee('Create')
        ->assertSee('Cancel')
        ->assertDontSee('Save Draft')
        ->assertDontSee('Post Inward');
});
