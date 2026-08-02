<?php

use App\Enums\RawMaterialInwardStatus;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Models\PackagingMaterial;
use App\Models\PackagingMaterialInward;
use App\Models\RawMaterial;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\PackagingMaterialInwardService;
use Illuminate\Validation\ValidationException;

function packagingInwardDirector(): User
{
    return User::query()->create([
        'name' => 'PMI Director',
        'email' => 'director.pmi.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
}

function packagingInwardSupervisor(): User
{
    return User::query()->create([
        'name' => 'PMI Supervisor',
        'email' => 'supervisor.pmi.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::ProductionSupervisor->value,
        'job_role' => 'Production Supervisor',
    ]);
}

function seedPackagingInwardMaterial(float $stock = 10, float $avg = 20, bool $active = true): PackagingMaterial
{
    return PackagingMaterial::query()->create([
        'packaging_name' => 'Carton '.uniqid(),
        'category' => 'Boxes',
        'unit' => 'Nos',
        'opening_stock' => $stock,
        'minimum_stock' => 2,
        'purchase_rate' => $avg,
        'average_rate' => $avg,
        'status' => $active,
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function samplePackagingInwardItems(PackagingMaterial $material, float $qty = 15, float $rate = 30): array
{
    return [[
        'packaging_material_id' => $material->id,
        'inward_quantity' => $qty,
        'basic_rate' => $rate,
        'discount_amount' => 0,
        'freight_amount' => 0,
        'other_charges' => 0,
        'gst_percentage' => 0,
    ]];
}

function samplePackagingInwardHeader(array $overrides = []): array
{
    return array_merge([
        'inward_date' => now()->toDateString(),
        'supplier_name' => 'Pack Supplier',
        'supplier_invoice_number' => 'PK-INV-'.uniqid(),
    ], $overrides);
}

it('creates and posts packaging inward with PMI number series and increases stock', function () {
    $supervisor = packagingInwardSupervisor();
    $material = seedPackagingInwardMaterial(10, 20);

    $inward = app(PackagingMaterialInwardService::class)->createAndPost(
        samplePackagingInwardHeader(),
        samplePackagingInwardItems($material, 15, 30),
        $supervisor,
    );

    expect($inward->status)->toBe(RawMaterialInwardStatus::Posted)
        ->and($inward->inward_number)->toStartWith('PMI')
        ->and($inward->items)->toHaveCount(1)
        ->and((float) $material->fresh()->current_stock)->toBe(25.0);
});

it('recalculates weighted average when creating and posting packaging inward', function () {
    $supervisor = packagingInwardSupervisor();
    $material = seedPackagingInwardMaterial(10, 20);

    $posted = app(PackagingMaterialInwardService::class)->createAndPost(
        samplePackagingInwardHeader(),
        samplePackagingInwardItems($material, 15, 30),
        $supervisor,
    );
    $material->refresh();
    $item = $posted->items->first();

    // ((10*20)+(15*30))/25 = 26
    expect($posted->status)->toBe(RawMaterialInwardStatus::Posted)
        ->and((float) $material->current_stock)->toBe(25.0)
        ->and((float) $material->average_rate)->toBe(26.0)
        ->and((float) $item->stock_before)->toBe(10.0)
        ->and((float) $item->stock_after)->toBe(25.0)
        ->and((float) $item->old_average_rate)->toBe(20.0)
        ->and((float) $item->new_average_rate)->toBe(26.0)
        ->and((float) $item->effective_unit_rate)->toBe(30.0);
});

it('excludes freight from taxable and adds freight after gst for packaging inventory costing', function () {
    $material = seedPackagingInwardMaterial();

    $calculated = app(PackagingMaterialInwardService::class)->calculateItemAmounts([
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
        ->and((float) $calculated['effective_unit_rate'])->toBe(117.64);
});

it('posts with gst and freight using effective rate for new average snapshots', function () {
    $supervisor = packagingInwardSupervisor();
    $material = seedPackagingInwardMaterial(10, 20);

    $posted = app(PackagingMaterialInwardService::class)->createAndPost(
        samplePackagingInwardHeader(),
        [[
            'packaging_material_id' => $material->id,
            'inward_quantity' => 10,
            'basic_rate' => 100,
            'discount_amount' => 50,
            'freight_amount' => 20,
            'other_charges' => 30,
            'gst_percentage' => 18,
        ]],
        $supervisor,
    );

    $item = $posted->items->first();
    $material->refresh();

    // Eff rate 117.64; new avg = ((10*20)+(10*117.64))/20 = 68.82
    expect((float) $item->effective_unit_rate)->toBe(117.64)
        ->and((float) $item->old_average_rate)->toBe(20.0)
        ->and((float) $item->new_average_rate)->toBe(68.82)
        ->and((float) $material->average_rate)->toBe(68.82)
        ->and((float) $material->current_stock)->toBe(20.0);
});

it('creates packaging stock ledger entry never on raw material ledger', function () {
    $supervisor = packagingInwardSupervisor();
    $material = seedPackagingInwardMaterial(10, 20);
    $raw = RawMaterial::query()->create([
        'material_name' => 'Raw '.uniqid(),
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => 5,
        'minimum_stock' => 1,
        'purchase_rate' => 10,
        'average_rate' => 10,
        'status' => true,
    ]);

    $posted = app(PackagingMaterialInwardService::class)->createAndPost(
        samplePackagingInwardHeader(['supplier_invoice_number' => 'PK-LEDGER-1']),
        samplePackagingInwardItems($material, 5, 30),
        $supervisor,
    );

    $ledger = StockLedger::query()
        ->where('packaging_material_id', $material->id)
        ->where('transaction_type', StockTransactionType::PackagingMaterialInward)
        ->first();

    expect($ledger)->not->toBeNull()
        ->and($ledger->raw_material_id)->toBeNull()
        ->and((float) $ledger->quantity_in)->toBe(5.0)
        ->and($ledger->reference_number)->toBe($posted->inward_number)
        ->and((float) $ledger->stock_before)->toBe(10.0)
        ->and((float) $ledger->stock_after)->toBe(15.0)
        ->and((float) $ledger->old_average_rate)->toBe(20.0)
        ->and((float) $ledger->new_average_rate)->toBe(23.3333)
        ->and(StockLedger::query()
            ->where('raw_material_id', $raw->id)
            ->where('transaction_type', StockTransactionType::PackagingMaterialInward)
            ->exists())->toBeFalse()
        ->and(StockLedger::query()
            ->where('transaction_type', StockTransactionType::RawMaterialInward)
            ->where('reference_number', $posted->inward_number)
            ->exists())->toBeFalse();
});

it('prevents duplicate packaging posting', function () {
    $supervisor = packagingInwardSupervisor();
    $material = seedPackagingInwardMaterial();

    $service = app(PackagingMaterialInwardService::class);
    $posted = $service->createAndPost(samplePackagingInwardHeader(), samplePackagingInwardItems($material), $supervisor);

    expect(fn () => $service->post($posted->fresh(), $supervisor))
        ->toThrow(ValidationException::class);
});

it('keeps posted packaging inward immutable from draft updates', function () {
    $supervisor = packagingInwardSupervisor();
    $director = packagingInwardDirector();
    $material = seedPackagingInwardMaterial();

    $service = app(PackagingMaterialInwardService::class);
    $posted = $service->createAndPost(samplePackagingInwardHeader(), samplePackagingInwardItems($material), $supervisor);

    expect(fn () => $service->updateDraft(
        $posted->fresh(),
        samplePackagingInwardHeader(['supplier_invoice_number' => 'CHANGED']),
        samplePackagingInwardItems($material, 1, 10),
        $supervisor,
    ))->toThrow(ValidationException::class);

    expect(fn () => $service->cancel($posted->fresh(), $director, 'oops'))
        ->toThrow(ValidationException::class);
});

it('rejects packaging submit and approve as removed from workflow', function () {
    $supervisor = packagingInwardSupervisor();
    $director = packagingInwardDirector();
    $material = seedPackagingInwardMaterial();

    $service = app(PackagingMaterialInwardService::class);
    $posted = $service->createAndPost(samplePackagingInwardHeader(), samplePackagingInwardItems($material), $supervisor);

    expect(fn () => $service->submitForApproval($posted->fresh(), $supervisor))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->approve($posted->fresh(), $director))
        ->toThrow(ValidationException::class);
});

it('rejects inactive packaging materials from inward lines', function () {
    $supervisor = packagingInwardSupervisor();
    $inactive = seedPackagingInwardMaterial(5, 10, false);
    $active = seedPackagingInwardMaterial(5, 10, true);
    $service = app(PackagingMaterialInwardService::class);

    expect(fn () => $service->createAndPost(
        samplePackagingInwardHeader(),
        samplePackagingInwardItems($inactive, 2, 12),
        $supervisor,
    ))->toThrow(ValidationException::class);

    $posted = $service->createAndPost(
        samplePackagingInwardHeader(),
        samplePackagingInwardItems($active, 2, 12),
        $supervisor,
    );

    expect($posted->status)->toBe(RawMaterialInwardStatus::Posted)
        ->and($posted->items->first()->packaging_material_id)->toBe($active->id)
        ->and(PackagingMaterial::query()->where('status', true)->pluck('id')->all())
        ->toContain($active->id)
        ->and(PackagingMaterial::query()->where('status', true)->pluck('id')->all())
        ->not->toContain($inactive->id);
});

it('does not seed stock when packaging master is created with zero opening stock', function () {
    $material = PackagingMaterial::query()->create([
        'packaging_name' => 'Empty Pack '.uniqid(),
        'category' => 'Bags',
        'unit' => 'Nos',
        'opening_stock' => 0,
        'minimum_stock' => 0,
        'purchase_rate' => 0,
        'average_rate' => 0,
        'status' => true,
    ]);

    expect((float) $material->current_stock)->toBe(0.0);
});

it('rolls back packaging create-and-post when item validation fails', function () {
    $supervisor = packagingInwardSupervisor();
    $material = seedPackagingInwardMaterial();
    $beforeCount = PackagingMaterialInward::query()->count();
    $beforeStock = (float) $material->fresh()->current_stock;

    expect(fn () => app(PackagingMaterialInwardService::class)->createAndPost(
        samplePackagingInwardHeader(),
        [[
            'packaging_material_id' => $material->id,
            'inward_quantity' => 0,
            'basic_rate' => 50,
        ]],
        $supervisor,
    ))->toThrow(ValidationException::class);

    expect(PackagingMaterialInward::query()->count())->toBe($beforeCount)
        ->and((float) $material->fresh()->current_stock)->toBe($beforeStock);
});

it('requires supplier invoice number for packaging inward', function () {
    $supervisor = packagingInwardSupervisor();
    $material = seedPackagingInwardMaterial();

    expect(fn () => app(PackagingMaterialInwardService::class)->createAndPost([
        'inward_date' => now()->toDateString(),
        'supplier_name' => 'Supplier A',
        'supplier_invoice_number' => '',
    ], samplePackagingInwardItems($material), $supervisor))->toThrow(ValidationException::class);
});

it('rejects negative discount freight other charges or gst for packaging', function () {
    $supervisor = packagingInwardSupervisor();
    $material = seedPackagingInwardMaterial();

    expect(fn () => app(PackagingMaterialInwardService::class)->createAndPost(
        samplePackagingInwardHeader(),
        [[
            'packaging_material_id' => $material->id,
            'inward_quantity' => 5,
            'basic_rate' => 20,
            'freight_amount' => -5,
        ]],
        $supervisor,
    ))->toThrow(ValidationException::class);
});
