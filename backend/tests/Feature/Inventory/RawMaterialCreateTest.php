<?php

use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Filament\Resources\RawMaterials\Pages\CreateRawMaterial;
use App\Filament\Resources\RawMaterials\Pages\EditRawMaterial;
use App\Filament\Resources\RawMaterials\Pages\ViewRawMaterial;
use App\Models\RawMaterial;
use App\Models\RawMaterialInward;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\MaterialEffectiveRate;
use App\Services\Inventory\RawMaterialCreateService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->director = User::query()->create([
        'name' => 'RM Create Director',
        'email' => 'rm.create.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
});

it('creates a raw material without stock when opening quantity is zero', function (): void {
    $material = app(RawMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Zero Opening Alloy',
            'unit' => 'Kg',
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
        ->and(RawMaterialInward::query()->count())->toBe(0)
        ->and(StockLedger::query()->where('raw_material_id', $material->id)->count())->toBe(0);
});

it('creates raw material with opening stock ledger and inventory update without creating an inward', function (): void {
    $beforeInwardCount = RawMaterialInward::query()->count();

    // Value 2411.8 @ qty 100 → Effective Rate 24.118 (same MaterialInwardCosting path).
    $material = app(RawMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Opening Ferrous',
            'unit' => 'Kg',
            'minimum_stock' => 10,
            'batch_tracking_enabled' => false,
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
        ->and(RawMaterialInward::query()->count())->toBe($beforeInwardCount);

    $ledger = StockLedger::query()
        ->where('raw_material_id', $material->id)
        ->where('transaction_type', StockTransactionType::OpeningStock->value)
        ->first();

    expect($ledger)->not->toBeNull()
        ->and((float) $ledger->quantity_in)->toBe(100.0)
        ->and($ledger->remarks)->toBe('Initial opening')
        ->and($ledger->reference_type)->toBe(RawMaterial::class)
        ->and((int) $ledger->reference_id)->toBe($material->id)
        ->and($ledger->reference_number)->toBe($material->material_code);
});

it('rejects opening stock with quantity but zero value', function (): void {
    expect(fn () => app(RawMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Invalid Opening',
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

    expect(RawMaterial::query()->where('material_name', 'Invalid Opening')->exists())->toBeFalse();
});

it('rejects opening stock value when quantity is zero', function (): void {
    expect(fn () => app(RawMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Value Without Qty',
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

    expect(RawMaterial::query()->where('material_name', 'Value Without Qty')->exists())->toBeFalse();
});

it('still accepts legacy purchase_rate opening payload for Effective Rate costing', function (): void {
    $material = app(RawMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Legacy Opening Alloy',
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
    expect((float) $material->average_rate)->toBe(24.118)
        ->and((float) $material->current_stock_value)->toBe(2411.8);
});

it('renders the simplified Opening Stock section on the Create Raw Material page', function (): void {
    $this->actingAs($this->director);

    Livewire::test(CreateRawMaterial::class)
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

it('shows opening and available effective rate per kg when stock unit is ton', function (): void {
    $material = app(RawMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Ton Rate Alloy',
            'unit' => 'Ton',
            'minimum_stock' => 0,
            'status' => true,
        ],
        opening: [
            'quantity' => 0.650,
            'value' => 110175,
            'date' => now('Asia/Kolkata')->toDateString(),
        ],
        user: $this->director,
    );

    expect((float) $material->opening_stock)->toBe(0.65)
        ->and((float) $material->current_stock)->toBe(0.65)
        ->and((float) $material->current_stock_value)->toBe(110175.0)
        ->and(app(MaterialEffectiveRate::class)->format(
            110175,
            0.650,
            'Ton',
        ))->toBe('₹169.50/Kg');

    $this->actingAs($this->director);

    Livewire::test(CreateRawMaterial::class)
        ->fillForm([
            'unit' => 'Ton',
            'opening_stock_quantity' => 0.650,
            'opening_stock_value' => 110175,
        ])
        ->assertSee('₹169.50/Kg')
        ->assertDontSee('₹169,500');

    Livewire::test(ViewRawMaterial::class, ['record' => $material->getKey()])
        ->assertSuccessful()
        ->assertSee('₹169.50/Kg')
        ->assertDontSee('₹169,500')
        ->assertSee('0.650');

    Livewire::test(EditRawMaterial::class, ['record' => $material->getKey()])
        ->assertSuccessful()
        ->assertSee('₹169.50/Kg')
        ->assertDontSee('₹169,500');
});
