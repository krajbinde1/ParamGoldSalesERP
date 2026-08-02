<?php

use App\Enums\RawMaterialInwardStatus;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Filament\Resources\RawMaterialInwards\Pages\ListRawMaterialInwards;
use App\Filament\Resources\RawMaterialInwards\Pages\ViewRawMaterialInward;
use App\Filament\Resources\RawMaterialInwards\RawMaterialInwardResource;
use App\Models\RawMaterial;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialInward;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\RawMaterialInwardService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function inwardDirector(): User
{
    return User::query()->create([
        'name' => 'Inward Director',
        'email' => 'director.inward.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
}

function inwardSupervisor(): User
{
    return User::query()->create([
        'name' => 'Inward Supervisor',
        'email' => 'supervisor.inward.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::ProductionSupervisor->value,
        'job_role' => 'Production Supervisor',
    ]);
}

function seedInwardMaterial(float $stock = 100, float $avg = 50, bool $batch = false): RawMaterial
{
    return RawMaterial::query()->create([
        'material_name' => 'Alloy '.uniqid(),
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => $stock,
        'minimum_stock' => 5,
        'purchase_rate' => $avg,
        'average_rate' => $avg,
        'batch_tracking_enabled' => $batch,
        'expiry_tracking_enabled' => $batch,
        'status' => true,
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function sampleInwardItems(RawMaterial $material, float $qty = 50, float $rate = 60): array
{
    return [[
        'raw_material_id' => $material->id,
        'inward_quantity' => $qty,
        'basic_rate' => $rate,
        'discount_amount' => 0,
        'freight_amount' => 0,
        'other_charges' => 0,
        'gst_percentage' => 0,
    ]];
}

function sampleInwardHeader(array $overrides = []): array
{
    return array_merge([
        'inward_date' => now()->toDateString(),
        'supplier_name' => 'ABC Metals',
        'supplier_invoice_number' => 'INV-'.uniqid(),
    ], $overrides);
}

it('creates and posts an inward with multiple materials in one step', function () {
    $supervisor = inwardSupervisor();
    $m1 = seedInwardMaterial();
    $m2 = seedInwardMaterial(20, 40);

    $inward = app(RawMaterialInwardService::class)->createAndPost(
        sampleInwardHeader(),
        [
            ...sampleInwardItems($m1, 10, 55),
            ...sampleInwardItems($m2, 5, 42),
        ],
        $supervisor,
    );

    expect($inward->status)->toBe(RawMaterialInwardStatus::Posted)
        ->and($inward->inward_number)->toStartWith('RMI')
        ->and($inward->items)->toHaveCount(2)
        ->and((float) $m1->fresh()->current_stock)->toBe(110.0)
        ->and((float) $m2->fresh()->current_stock)->toBe(25.0);
});

it('increases stock when creating and posting inward', function () {
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial(100, 50);

    $posted = app(RawMaterialInwardService::class)->createAndPost(
        sampleInwardHeader(),
        sampleInwardItems($material, 40, 60),
        $supervisor,
    );

    expect($posted->status)->toBe(RawMaterialInwardStatus::Posted)
        ->and((float) $material->fresh()->current_stock)->toBe(140.0);
});

it('recalculates weighted average rate on create-and-post using effective unit rate', function () {
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial(100, 50);

    $posted = app(RawMaterialInwardService::class)->createAndPost(
        sampleInwardHeader(),
        sampleInwardItems($material, 50, 60),
        $supervisor,
    );

    $material->refresh();
    $item = $posted->items->first();
    // ((100*50)+(50*60))/150 = 53.3333
    expect((float) $material->average_rate)->toBe(53.3333)
        ->and((float) $material->current_stock_value)->toBe(8000.0)
        ->and((float) $item->stock_before)->toBe(100.0)
        ->and((float) $item->stock_after)->toBe(150.0)
        ->and((float) $item->old_average_rate)->toBe(50.0)
        ->and((float) $item->new_average_rate)->toBe(53.3333)
        ->and((float) $item->effective_unit_rate)->toBe(60.0);
});

it('excludes freight from taxable and adds freight after gst for inventory costing', function () {
    $material = seedInwardMaterial();

    $calculated = app(RawMaterialInwardService::class)->calculateItemAmounts([
        'inward_quantity' => 10,
        'basic_rate' => 100,
        'discount_amount' => 50,
        'freight_amount' => 20,
        'other_charges' => 30,
        'gst_percentage' => 18,
    ], $material);

    // Taxable = 1000 - 50 + 30 = 980 (freight NOT taxable)
    // GST = 980 * 18% = 176.4
    // Total (display) = 980 + 176.4 = 1156.4 (excludes freight)
    // Effective inventory value = 1156.4 + 20 = 1176.4
    // Effective rate = 1176.4 / 10 = 117.64
    expect((float) $calculated['taxable_amount'])->toBe(980.0)
        ->and((float) $calculated['igst_amount'])->toBe(176.4)
        ->and((float) $calculated['total_amount'])->toBe(1156.4)
        ->and((float) $calculated['landed_cost'])->toBe(1176.4)
        ->and((float) $calculated['effective_unit_rate'])->toBe(117.64)
        ->and((float) $calculated['rejected_quantity'])->toBe(0.0)
        ->and((float) $calculated['accepted_quantity'])->toBe(10.0);
});

it('applies acceptance formula with freight non-taxable after gst', function () {
    $material = seedInwardMaterial(0, 0);

    $calculated = app(RawMaterialInwardService::class)->calculateItemAmounts([
        'inward_quantity' => 5,
        'basic_rate' => 10000,
        'discount_amount' => 0,
        'freight_amount' => 1000,
        'other_charges' => 0,
        'gst_percentage' => 18,
    ], $material);

    // Base 50000; Taxable 50000; GST 9000; Total 59000; Effective 60000; Rate 12000
    expect((float) $calculated['taxable_amount'])->toBe(50000.0)
        ->and((float) $calculated['igst_amount'])->toBe(9000.0)
        ->and((float) $calculated['total_amount'])->toBe(59000.0)
        ->and((float) $calculated['landed_cost'])->toBe(60000.0)
        ->and((float) $calculated['effective_unit_rate'])->toBe(12000.0);

    $supervisor = inwardSupervisor();
    $posted = app(RawMaterialInwardService::class)->createAndPost(
        sampleInwardHeader(),
        [[
            'raw_material_id' => $material->id,
            'inward_quantity' => 5,
            'basic_rate' => 10000,
            'discount_amount' => 0,
            'freight_amount' => 1000,
            'other_charges' => 0,
            'gst_percentage' => 18,
        ]],
        $supervisor,
    );

    $item = $posted->items->first();
    $ledger = StockLedger::query()
        ->where('raw_material_id', $material->id)
        ->where('transaction_type', StockTransactionType::RawMaterialInward)
        ->first();

    expect((float) $posted->grand_total)->toBe(60000.0)
        ->and((float) $item->taxable_amount)->toBe(50000.0)
        ->and((float) $item->igst_amount)->toBe(9000.0)
        ->and((float) $item->total_amount)->toBe(59000.0)
        ->and((float) $item->landed_cost)->toBe(60000.0)
        ->and((float) $item->effective_unit_rate)->toBe(12000.0)
        ->and((float) $material->fresh()->average_rate)->toBe(12000.0)
        ->and((float) $material->fresh()->current_stock_value)->toBe(60000.0)
        ->and($ledger)->not->toBeNull()
        ->and((float) $ledger->rate)->toBe(12000.0)
        ->and((float) $ledger->inward_value)->toBe(60000.0)
        ->and((float) $ledger->transaction_value)->toBe(60000.0);
});

it('updates taxable gst and stock via reverse-then-repost when freight changes on posted inward', function () {
    $director = inwardDirector();
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial(0, 0);
    $service = app(RawMaterialInwardService::class);

    $posted = $service->createAndPost(
        sampleInwardHeader(['supplier_invoice_number' => 'INV-FRT-1']),
        [[
            'raw_material_id' => $material->id,
            'inward_quantity' => 5,
            'basic_rate' => 10000,
            'discount_amount' => 0,
            'freight_amount' => 0,
            'other_charges' => 0,
            'gst_percentage' => 18,
        ]],
        $supervisor,
    );

    // Without freight: Taxable 50000, GST 9000, Effective 59000
    expect((float) $posted->items->first()->landed_cost)->toBe(59000.0);

    $updated = $service->update(
        $posted->fresh(),
        sampleInwardHeader([
            'supplier_invoice_number' => 'INV-FRT-2',
        ]),
        [[
            'raw_material_id' => $material->id,
            'inward_quantity' => 5,
            'basic_rate' => 10000,
            'discount_amount' => 0,
            'freight_amount' => 1000,
            'other_charges' => 0,
            'gst_percentage' => 18,
        ]],
        $director,
    );

    $item = $updated->items->first();
    $material->refresh();

    // Freight added after GST: Taxable still 50000, GST still 9000, Total 59000, Effective 60000
    expect((float) $item->taxable_amount)->toBe(50000.0)
        ->and((float) $item->igst_amount)->toBe(9000.0)
        ->and((float) $item->total_amount)->toBe(59000.0)
        ->and((float) $item->landed_cost)->toBe(60000.0)
        ->and((float) $item->effective_unit_rate)->toBe(12000.0)
        ->and((float) $updated->grand_total)->toBe(60000.0)
        ->and((float) $material->average_rate)->toBe(12000.0)
        ->and((float) $material->current_stock_value)->toBe(60000.0);
});

it('rejects discount greater than purchase value', function () {
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial();

    expect(fn () => app(RawMaterialInwardService::class)->createAndPost(
        sampleInwardHeader(),
        [[
            'raw_material_id' => $material->id,
            'inward_quantity' => 10,
            'basic_rate' => 50,
            'discount_amount' => 501,
        ]],
        $supervisor,
    ))->toThrow(ValidationException::class);
});

it('creates stock ledger entries with rate snapshots for inward posting', function () {
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial(10, 20);

    $posted = app(RawMaterialInwardService::class)->createAndPost(
        sampleInwardHeader(['supplier_invoice_number' => 'INV-9']),
        sampleInwardItems($material, 5, 30),
        $supervisor,
    );

    $ledger = StockLedger::query()
        ->where('raw_material_id', $material->id)
        ->where('transaction_type', StockTransactionType::RawMaterialInward)
        ->first();

    expect($ledger)->not->toBeNull()
        ->and((float) $ledger->quantity_in)->toBe(5.0)
        ->and($ledger->reference_number)->toBe($posted->inward_number)
        ->and((float) $ledger->stock_before)->toBe(10.0)
        ->and((float) $ledger->stock_after)->toBe(15.0)
        ->and((float) $ledger->old_average_rate)->toBe(20.0)
        ->and((float) $ledger->new_average_rate)->toBe(23.3333);
});

it('prevents duplicate posting', function () {
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial();

    $service = app(RawMaterialInwardService::class);
    $posted = $service->createAndPost(sampleInwardHeader(), sampleInwardItems($material), $supervisor);

    expect(fn () => $service->post($posted->fresh(), $supervisor))
        ->toThrow(ValidationException::class);
});

it('rejects submit and approve as removed from inward workflow', function () {
    $supervisor = inwardSupervisor();
    $director = inwardDirector();
    $material = seedInwardMaterial();

    $service = app(RawMaterialInwardService::class);
    $posted = $service->createAndPost(sampleInwardHeader(), sampleInwardItems($material), $supervisor);

    expect(fn () => $service->submitForApproval($posted->fresh(), $supervisor))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->approve($posted->fresh(), $director))
        ->toThrow(ValidationException::class);
});

it('creates batch stock when batch tracking is enabled', function () {
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial(0, 50, true);

    $posted = app(RawMaterialInwardService::class)->createAndPost(
        sampleInwardHeader(),
        sampleInwardItems($material, 20, 55),
        $supervisor,
    );

    $batch = RawMaterialBatch::query()->where('inward_id', $posted->id)->first();

    expect($batch)->not->toBeNull()
        ->and((float) $batch->available_quantity)->toBe(20.0)
        ->and((float) $batch->accepted_quantity)->toBe(20.0)
        ->and($batch->internal_batch_number)->not->toBeEmpty();
});

it('posts inward return and deducts stock from batch', function () {
    $director = inwardDirector();
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial(0, 50, true);

    $service = app(RawMaterialInwardService::class);
    $posted = $service->createAndPost(
        sampleInwardHeader(),
        sampleInwardItems($material, 30, 50),
        $supervisor,
    );

    $batch = RawMaterialBatch::query()->where('inward_id', $posted->id)->firstOrFail();

    $return = $service->createAndPostReturn([
        'raw_material_inward_id' => $posted->id,
        'raw_material_inward_item_id' => $posted->items->first()->id,
        'raw_material_id' => $material->id,
        'raw_material_batch_id' => $batch->id,
        'return_date' => now()->toDateString(),
        'return_quantity' => 10,
        'reason' => 'Damaged on receipt',
    ], $director);

    expect((float) $material->fresh()->current_stock)->toBe(20.0)
        ->and((float) $batch->fresh()->available_quantity)->toBe(20.0)
        ->and($return->return_number)->toStartWith('IRR')
        ->and(StockLedger::query()->where('transaction_type', StockTransactionType::PurchaseReturn)->exists())->toBeTrue();
});

it('rolls back the transaction when item validation fails mid-create', function () {
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial();
    $beforeCount = RawMaterialInward::query()->count();
    $beforeStock = (float) $material->fresh()->current_stock;

    expect(fn () => app(RawMaterialInwardService::class)->createAndPost(
        sampleInwardHeader(),
        [[
            'raw_material_id' => $material->id,
            'inward_quantity' => 0,
            'basic_rate' => 50,
        ]],
        $supervisor,
    ))->toThrow(ValidationException::class);

    expect(RawMaterialInward::query()->count())->toBe($beforeCount)
        ->and((float) $material->fresh()->current_stock)->toBe($beforeStock);
});

it('rejects zero or negative purchase rate', function () {
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial();

    expect(fn () => app(RawMaterialInwardService::class)->createAndPost(
        sampleInwardHeader(),
        [[
            'raw_material_id' => $material->id,
            'inward_quantity' => 10,
            'basic_rate' => 0,
        ]],
        $supervisor,
    ))->toThrow(ValidationException::class);
});

it('rejects negative discount freight other charges or gst', function () {
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial();

    expect(fn () => app(RawMaterialInwardService::class)->createAndPost(
        sampleInwardHeader(),
        [[
            'raw_material_id' => $material->id,
            'inward_quantity' => 10,
            'basic_rate' => 50,
            'discount_amount' => -1,
        ]],
        $supervisor,
    ))->toThrow(ValidationException::class);
});

it('requires supplier invoice number', function () {
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial();

    expect(fn () => app(RawMaterialInwardService::class)->createAndPost([
        'inward_date' => now()->toDateString(),
        'supplier_name' => 'Supplier A',
        'supplier_invoice_number' => '',
    ], sampleInwardItems($material), $supervisor))->toThrow(ValidationException::class);
});

it('keeps posted inward immutable from draft updates and cancellation', function () {
    $supervisor = inwardSupervisor();
    $director = inwardDirector();
    $material = seedInwardMaterial();

    $service = app(RawMaterialInwardService::class);
    $posted = $service->createAndPost(sampleInwardHeader(), sampleInwardItems($material), $supervisor);

    expect(fn () => $service->updateDraft(
        $posted->fresh(),
        sampleInwardHeader(['supplier_invoice_number' => 'CHANGED']),
        sampleInwardItems($material, 1, 10),
        $supervisor,
    ))->toThrow(ValidationException::class);

    expect(fn () => $service->cancel($posted->fresh(), $director, 'oops'))
        ->toThrow(ValidationException::class);
});

it('resolves the list ViewAction URL and loads the posted inward view page', function () {
    $director = inwardDirector();
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial();

    $posted = app(RawMaterialInwardService::class)->createAndPost(
        sampleInwardHeader(['supplier_name' => 'View Test Supplier']),
        sampleInwardItems($material, 12, 55),
        $supervisor,
    );

    $viewUrl = RawMaterialInwardResource::getUrl('view', ['record' => $posted]);

    expect($viewUrl)->toContain('/admin/raw-material-inwards/'.$posted->getRouteKey())
        ->and($posted->getRouteKeyName())->toBe('id');

    $this->actingAs($director);

    Livewire::test(ListRawMaterialInwards::class)
        ->assertSuccessful()
        ->assertSee($posted->inward_number)
        ->assertSee('/admin/raw-material-inwards/'.$posted->getRouteKey());

    Livewire::test(ViewRawMaterialInward::class, ['record' => $posted->getRouteKey()])
        ->assertSuccessful()
        ->assertSee($posted->inward_number)
        ->assertSee('Inward Header')
        ->assertSee('Material Items')
        ->assertSee('Inward Summary')
        ->assertSee('View Test Supplier')
        ->assertSee($material->material_name);
});

it('shows EditAction for draft inwards when director has update permission', function () {
    $director = inwardDirector();
    $material = seedInwardMaterial();

    $draft = app(RawMaterialInwardService::class)->createDraft(
        sampleInwardHeader(),
        sampleInwardItems($material, 5, 50),
        $director,
    );

    $this->actingAs($director);

    expect($director->can('update', $draft))->toBeTrue()
        ->and(RawMaterialInwardResource::canSeeEditAction($draft))->toBeTrue()
        ->and(RawMaterialInwardResource::canEdit($draft))->toBeTrue();

    Livewire::test(ListRawMaterialInwards::class)
        ->assertSuccessful()
        ->assertSee($draft->inward_number)
        ->assertSee('/admin/raw-material-inwards/'.$draft->getRouteKey().'/edit');
});

it('shows Edit enabled for posted inward without dependents and disables when later stock exists', function () {
    $director = inwardDirector();
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial(100, 50);

    $service = app(RawMaterialInwardService::class);
    $posted = $service->createAndPost(
        sampleInwardHeader(),
        sampleInwardItems($material, 20, 60),
        $supervisor,
    );

    $this->actingAs($director);

    expect($director->can('update', $posted->fresh()))->toBeTrue()
        ->and(RawMaterialInwardResource::canEdit($posted->fresh()))->toBeTrue()
        ->and(RawMaterialInwardResource::editLockTooltip($posted->fresh()))->toBeNull();

    // Subsequent inward locks the first posted document.
    $service->createAndPost(
        sampleInwardHeader(),
        sampleInwardItems($material, 5, 55),
        $supervisor,
    );

    $locked = $posted->fresh();
    expect($locked->hasSubsequentStockTransactions())->toBeTrue()
        ->and($director->can('update', $locked))->toBeFalse()
        ->and(RawMaterialInwardResource::canSeeEditAction($locked))->toBeTrue()
        ->and(RawMaterialInwardResource::canEdit($locked))->toBeFalse()
        ->and(RawMaterialInwardResource::editLockTooltip($locked))
        ->toBe('Cannot edit because subsequent stock transactions exist.');
});

it('allows authorized reverse-then-repost edit of posted inward without dependents', function () {
    $director = inwardDirector();
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial(100, 50);

    $service = app(RawMaterialInwardService::class);
    $posted = $service->createAndPost(
        sampleInwardHeader(['supplier_invoice_number' => 'INV-EDIT-1']),
        sampleInwardItems($material, 50, 60),
        $supervisor,
    );

    $inwardNumber = $posted->inward_number;
    $beforeLedgers = StockLedger::query()
        ->where('raw_material_id', $material->id)
        ->where('transaction_type', StockTransactionType::RawMaterialInward)
        ->count();

    $updated = $service->update(
        $posted->fresh(),
        sampleInwardHeader([
            'supplier_name' => 'Updated Supplier',
            'supplier_invoice_number' => 'INV-EDIT-2',
        ]),
        sampleInwardItems($material, 40, 70),
        $director,
    );

    $material->refresh();
    // ((100*50)+(40*70))/140 = 55.7143
    expect($updated->status)->toBe(RawMaterialInwardStatus::Posted)
        ->and($updated->inward_number)->toBe($inwardNumber)
        ->and($updated->supplier_invoice_number)->toBe('INV-EDIT-2')
        ->and((float) $material->current_stock)->toBe(140.0)
        ->and((float) $material->average_rate)->toBe(55.7143)
        ->and(StockLedger::query()
            ->where('raw_material_id', $material->id)
            ->where('transaction_type', StockTransactionType::BatchReversal)
            ->where('reference_id', $updated->id)
            ->exists())->toBeTrue()
        ->and(StockLedger::query()
            ->where('raw_material_id', $material->id)
            ->where('transaction_type', StockTransactionType::RawMaterialInward)
            ->count())->toBe($beforeLedgers + 1);
});

it('rejects posted edit when subsequent stock transactions exist', function () {
    $director = inwardDirector();
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial(100, 50);
    $service = app(RawMaterialInwardService::class);

    $posted = $service->createAndPost(
        sampleInwardHeader(),
        sampleInwardItems($material, 20, 60),
        $supervisor,
    );

    $service->createAndPost(
        sampleInwardHeader(),
        sampleInwardItems($material, 5, 55),
        $supervisor,
    );

    expect(fn () => $service->update(
        $posted->fresh(),
        sampleInwardHeader(['supplier_invoice_number' => 'CHANGED']),
        sampleInwardItems($material, 10, 50),
        $director,
    ))->toThrow(ValidationException::class);
});

it('returns 403 for unauthorized edit page and denies production supervisor update policy', function () {
    $director = inwardDirector();
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial();

    $service = app(RawMaterialInwardService::class);
    $posted = $service->createAndPost(
        sampleInwardHeader(),
        sampleInwardItems($material, 10, 50),
        $supervisor,
    );

    $this->actingAs($supervisor);

    expect($supervisor->can('update', $posted->fresh()))->toBeFalse()
        ->and(RawMaterialInwardResource::canSeeEditAction($posted->fresh()))->toBeFalse();

    Livewire::test(\App\Filament\Resources\RawMaterialInwards\Pages\EditRawMaterialInward::class, [
        'record' => $posted->getRouteKey(),
    ])->assertForbidden();

    // Locked posted: director also gets 403 on direct edit URL.
    $service->createAndPost(
        sampleInwardHeader(),
        sampleInwardItems($material, 1, 50),
        $supervisor,
    );

    $this->actingAs($director);

    Livewire::test(\App\Filament\Resources\RawMaterialInwards\Pages\EditRawMaterialInward::class, [
        'record' => $posted->getRouteKey(),
    ])->assertForbidden();
});

it('loads the edit page for an unlocked posted inward', function () {
    $director = inwardDirector();
    $supervisor = inwardSupervisor();
    $material = seedInwardMaterial();

    $posted = app(RawMaterialInwardService::class)->createAndPost(
        sampleInwardHeader(['supplier_invoice_number' => 'INV-EDIT-PAGE']),
        sampleInwardItems($material, 8, 52),
        $supervisor,
    );

    $this->actingAs($director);

    Livewire::test(\App\Filament\Resources\RawMaterialInwards\Pages\EditRawMaterialInward::class, [
        'record' => $posted->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertSee($posted->inward_number)
        ->assertSee('Record Info')
        ->assertSee('Created By')
        ->assertSet('data.supplier_invoice_number', 'INV-EDIT-PAGE');
});
