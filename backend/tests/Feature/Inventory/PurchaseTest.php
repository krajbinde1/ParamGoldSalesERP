<?php

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\PurchaseMaterialType;
use App\Enums\PurchaseStatus;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\PackagingMaterial;
use App\Models\Purchase;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Models\StockLedger;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventory\BOMCalculationService;
use App\Services\Inventory\PurchaseService;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function purchaseDirector(): User
{
    return User::query()->create([
        'name' => 'Purchase Director',
        'email' => 'director.purchase.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
}

function purchaseSupervisor(): User
{
    return User::query()->create([
        'name' => 'Purchase Supervisor',
        'email' => 'supervisor.purchase.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::ProductionSupervisor->value,
        'job_role' => 'Production Supervisor',
    ]);
}

function purchaseSupplier(string $name = 'ABC Metals'): Supplier
{
    return Supplier::query()->create([
        'supplier_name' => $name.' '.uniqid(),
        'status' => true,
    ]);
}

function purchaseRawMaterial(float $stock = 100, float $avg = 50): RawMaterial
{
    return RawMaterial::query()->create([
        'material_name' => 'Alloy '.uniqid(),
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => $stock,
        'minimum_stock' => 5,
        'purchase_rate' => $avg,
        'average_rate' => $avg,
        'status' => true,
    ]);
}

function purchasePackagingMaterial(float $stock = 40, float $avg = 10): PackagingMaterial
{
    return PackagingMaterial::query()->create([
        'packaging_name' => 'Bag '.uniqid(),
        'unit' => 'Nos',
        'opening_stock' => $stock,
        'purchase_rate' => $avg,
        'average_rate' => $avg,
        'status' => true,
    ]);
}

/**
 * @return array<string, mixed>
 */
function purchaseHeader(Supplier $supplier, PurchaseMaterialType $type = PurchaseMaterialType::RawMaterial, array $overrides = []): array
{
    return array_merge([
        'purchase_date' => now('Asia/Kolkata')->toDateString(),
        'supplier_id' => $supplier->id,
        'supplier_invoice_number' => 'INV-'.uniqid(),
        'supplier_invoice_date' => now('Asia/Kolkata')->toDateString(),
        'material_type' => $type->value,
        'remarks' => 'Test purchase',
    ], $overrides);
}

it('saves a purchase as draft without changing stock', function (): void {
    $director = purchaseDirector();
    $supplier = purchaseSupplier();
    $material = purchaseRawMaterial(100, 50);

    $purchase = app(PurchaseService::class)->create(
        purchaseHeader($supplier),
        [[
            'raw_material_id' => $material->id,
            'quantity' => 20,
            'purchase_rate' => 60,
            'gst_percentage' => 18,
        ]],
        $director,
    );

    expect($purchase->status)->toBe(PurchaseStatus::Draft)
        ->and($purchase->purchase_number)->toStartWith('PUR')
        ->and((float) $purchase->total_quantity)->toBe(20.0)
        ->and((float) $purchase->total_taxable_amount)->toBe(1200.0)
        ->and((float) $purchase->total_gst)->toBe(216.0)
        ->and((float) $purchase->grand_total)->toBe(1416.0)
        ->and((float) $material->fresh()->current_stock)->toBe(100.0)
        ->and(StockLedger::query()->where('reference_id', $purchase->id)->count())->toBe(0);
});

it('confirms a raw material purchase and increases available stock with a purchase ledger', function (): void {
    $director = purchaseDirector();
    $supplier = purchaseSupplier('Steel House');
    $material = purchaseRawMaterial(100, 50);

    $purchase = app(PurchaseService::class)->create(
        purchaseHeader($supplier),
        [[
            'raw_material_id' => $material->id,
            'quantity' => 50,
            'purchase_rate' => 60,
            'gst_percentage' => 0,
            'batch_lot_no' => 'LOT-1',
        ]],
        $director,
        confirm: true,
    );

    $material = $material->fresh();
    $ledger = StockLedger::query()
        ->where('raw_material_id', $material->id)
        ->where('transaction_type', StockTransactionType::Purchase)
        ->first();

    // WAVG = (100*50 + 50*60) / 150 = 53.3333
    expect($purchase->status)->toBe(PurchaseStatus::Confirmed)
        ->and((float) $material->current_stock)->toBe(150.0)
        ->and((float) $material->average_rate)->toBe(53.3333)
        ->and((float) $material->current_stock_value)->toBe(8000.0)
        ->and($ledger)->not->toBeNull()
        ->and((float) $ledger->quantity_in)->toBe(50.0)
        ->and((float) $ledger->rate)->toBe(60.0)
        ->and($ledger->reference_number)->toBe($purchase->purchase_number)
        ->and($ledger->supplier_invoice_number)->toBe($purchase->supplier_invoice_number)
        ->and($ledger->remarks)->toContain($purchase->purchase_number)
        ->and($ledger->remarks)->toContain('Steel House');
});

it('confirms a packing material purchase and increases packing available stock', function (): void {
    $director = purchaseDirector();
    $supplier = purchaseSupplier('Pack Co');
    $material = purchasePackagingMaterial(40, 10);

    $purchase = app(PurchaseService::class)->create(
        purchaseHeader($supplier, PurchaseMaterialType::PackingMaterial),
        [[
            'packaging_material_id' => $material->id,
            'quantity' => 10,
            'purchase_rate' => 12,
            'gst_percentage' => 0,
        ]],
        $director,
        confirm: true,
    );

    $material = $material->fresh();
    expect($purchase->material_type)->toBe(PurchaseMaterialType::PackingMaterial)
        ->and((float) $material->current_stock)->toBe(50.0)
        ->and(StockLedger::query()->where('packaging_material_id', $material->id)->where('transaction_type', StockTransactionType::Purchase)->count())->toBe(1);
});

it('does not create a new raw material master when purchasing', function (): void {
    $director = purchaseDirector();
    $supplier = purchaseSupplier();
    $before = RawMaterial::query()->count();

    expect(fn () => app(PurchaseService::class)->create(
        purchaseHeader($supplier),
        [[
            'raw_material_id' => 0,
            'quantity' => 5,
            'purchase_rate' => 10,
            'gst_percentage' => 0,
        ]],
        $director,
    ))->toThrow(ValidationException::class);

    expect(RawMaterial::query()->count())->toBe($before);
});

it('cancels a confirmed purchase and reverses stock without leaving a duplicate inward', function (): void {
    $director = purchaseDirector();
    $supplier = purchaseSupplier();
    $material = purchaseRawMaterial(100, 50);

    $purchase = app(PurchaseService::class)->create(
        purchaseHeader($supplier),
        [[
            'raw_material_id' => $material->id,
            'quantity' => 20,
            'purchase_rate' => 80,
            'gst_percentage' => 0,
        ]],
        $director,
        confirm: true,
    );

    expect((float) $material->fresh()->current_stock)->toBe(120.0);

    $cancelled = app(PurchaseService::class)->cancel($purchase, $director, 'Wrong invoice');
    $material = $material->fresh();

    expect($cancelled->status)->toBe(PurchaseStatus::Cancelled)
        ->and((float) $material->current_stock)->toBe(100.0)
        ->and((float) $material->average_rate)->toBe(50.0)
        ->and((float) $material->current_stock_value)->toBe(5000.0)
        ->and(StockLedger::query()->where('reference_id', $purchase->id)->where('transaction_type', StockTransactionType::Purchase)->count())->toBe(1)
        ->and(StockLedger::query()->where('reference_id', $purchase->id)->where('transaction_type', StockTransactionType::PurchaseReturn)->count())->toBe(1);
});

it('edits a confirmed purchase by posting only the stock difference', function (): void {
    $director = purchaseDirector();
    $supplier = purchaseSupplier();
    $material = purchaseRawMaterial(100, 50);

    $purchase = app(PurchaseService::class)->create(
        purchaseHeader($supplier),
        [[
            'raw_material_id' => $material->id,
            'quantity' => 20,
            'purchase_rate' => 50,
            'gst_percentage' => 0,
        ]],
        $director,
        confirm: true,
    );

    expect((float) $material->fresh()->current_stock)->toBe(120.0);

    $updated = app(PurchaseService::class)->update(
        $purchase,
        purchaseHeader($supplier, PurchaseMaterialType::RawMaterial, [
            'supplier_invoice_number' => $purchase->supplier_invoice_number,
        ]),
        [[
            'raw_material_id' => $material->id,
            'quantity' => 30,
            'purchase_rate' => 50,
            'gst_percentage' => 0,
        ]],
        $director,
    );

    $material = $material->fresh();
    $purchaseInQty = (float) StockLedger::query()
        ->where('raw_material_id', $material->id)
        ->where('transaction_type', StockTransactionType::Purchase)
        ->sum('quantity_in');
    $purchaseOutQty = (float) StockLedger::query()
        ->where('raw_material_id', $material->id)
        ->where('transaction_type', StockTransactionType::PurchaseReturn)
        ->sum('quantity_out');

    expect($updated->status)->toBe(PurchaseStatus::Confirmed)
        ->and((float) $updated->total_quantity)->toBe(30.0)
        ->and((float) $material->current_stock)->toBe(130.0)
        ->and($purchaseInQty - $purchaseOutQty)->toBe(30.0);
});

it('does not change stock when a draft purchase is updated', function (): void {
    $director = purchaseDirector();
    $supplier = purchaseSupplier();
    $material = purchaseRawMaterial(80, 40);

    $purchase = app(PurchaseService::class)->create(
        purchaseHeader($supplier),
        [[
            'raw_material_id' => $material->id,
            'quantity' => 5,
            'purchase_rate' => 40,
            'gst_percentage' => 0,
        ]],
        $director,
    );

    app(PurchaseService::class)->update(
        $purchase,
        purchaseHeader($supplier, PurchaseMaterialType::RawMaterial, [
            'supplier_invoice_number' => $purchase->supplier_invoice_number,
        ]),
        [[
            'raw_material_id' => $material->id,
            'quantity' => 15,
            'purchase_rate' => 45,
            'gst_percentage' => 5,
        ]],
        $director,
    );

    expect((float) $material->fresh()->current_stock)->toBe(80.0)
        ->and((float) $purchase->fresh()->total_quantity)->toBe(15.0)
        ->and($purchase->fresh()->status)->toBe(PurchaseStatus::Draft);
});

it('lists purchases for inventory users', function (): void {
    $director = purchaseDirector();
    $supplier = purchaseSupplier();
    $material = purchaseRawMaterial();

    app(PurchaseService::class)->create(
        purchaseHeader($supplier),
        [[
            'raw_material_id' => $material->id,
            'quantity' => 2,
            'purchase_rate' => 10,
            'gst_percentage' => 0,
        ]],
        $director,
    );

    Livewire::actingAs($director)
        ->test(ListPurchases::class)
        ->assertSuccessful()
        ->assertSee('Purchase No.')
        ->assertSee('Material Type')
        ->assertSee('Grand Total');
});

it('lets a production supervisor confirm a draft but not cancel a confirmed purchase', function (): void {
    $supervisor = purchaseSupervisor();
    $director = purchaseDirector();
    $supplier = purchaseSupplier();
    $material = purchaseRawMaterial(10, 20);

    $purchase = app(PurchaseService::class)->create(
        purchaseHeader($supplier),
        [[
            'raw_material_id' => $material->id,
            'quantity' => 2,
            'purchase_rate' => 20,
            'gst_percentage' => 0,
        ]],
        $supervisor,
    );

    $confirmed = app(PurchaseService::class)->confirm($purchase, $supervisor);
    expect($confirmed->status)->toBe(PurchaseStatus::Confirmed);

    expect(fn () => app(PurchaseService::class)->cancel($confirmed, $supervisor, 'nope'))
        ->toThrow(ValidationException::class);

    app(PurchaseService::class)->cancel($confirmed, $director, 'director cancel');
    expect($confirmed->fresh()->status)->toBe(PurchaseStatus::Cancelled);
});

it('recalculates GST-exclusive weighted average stock rate on purchase confirm', function (): void {
    $director = purchaseDirector();
    $supplier = purchaseSupplier();
    $material = purchaseRawMaterial(100, 80);

    $purchase = app(PurchaseService::class)->create(
        purchaseHeader($supplier),
        [[
            'raw_material_id' => $material->id,
            'quantity' => 100,
            'purchase_rate' => 100,
            'gst_percentage' => 18,
        ]],
        $director,
        confirm: true,
    );

    $material = $material->fresh();
    $item = $purchase->items()->first();
    $ledger = StockLedger::query()
        ->where('raw_material_id', $material->id)
        ->where('transaction_type', StockTransactionType::Purchase)
        ->first();

    expect((float) $purchase->total_taxable_amount)->toBe(10000.0)
        ->and((float) $purchase->total_gst)->toBe(1800.0)
        ->and((float) $purchase->grand_total)->toBe(11800.0)
        ->and((float) $item->purchase_rate)->toBe(100.0)
        ->and((float) $material->current_stock)->toBe(200.0)
        ->and((float) $material->average_rate)->toBe(90.0)
        ->and((float) $material->current_stock_value)->toBe(18000.0)
        ->and((float) $material->purchase_rate)->toBe(100.0)
        ->and((float) $ledger->rate)->toBe(100.0)
        ->and((float) $ledger->transaction_value)->toBe(10000.0)
        ->and((float) $ledger->new_average_rate)->toBe(90.0)
        ->and($ledger->remarks)->toContain('(ex GST)');
});

it('reduces manufacturing consumption at the current weighted average rate', function (): void {
    $director = purchaseDirector();
    $supplier = purchaseSupplier();
    $material = purchaseRawMaterial(100, 80);

    app(PurchaseService::class)->create(
        purchaseHeader($supplier),
        [[
            'raw_material_id' => $material->id,
            'quantity' => 100,
            'purchase_rate' => 100,
            'gst_percentage' => 18,
        ]],
        $director,
        confirm: true,
    );

    $material = $material->fresh();
    expect((float) $material->average_rate)->toBe(90.0);

    app(StockLedgerService::class)->postRawMaterialMovement(
        $material,
        0,
        50,
        (float) $material->average_rate,
        [
            'transaction_date' => now('Asia/Kolkata')->toDateString(),
            'transaction_type' => StockTransactionType::ProductionConsumption,
            'remarks' => 'Production consumption',
        ],
        $director,
    );

    $material = $material->fresh();
    $consumption = StockLedger::query()
        ->where('raw_material_id', $material->id)
        ->where('transaction_type', StockTransactionType::ProductionConsumption)
        ->first();

    expect((float) $material->current_stock)->toBe(150.0)
        ->and((float) $material->average_rate)->toBe(90.0)
        ->and((float) $material->current_stock_value)->toBe(13500.0)
        ->and((float) $consumption->rate)->toBe(90.0)
        ->and((float) $consumption->transaction_value)->toBe(4500.0);
});

it('uses the latest weighted average rate for BOM estimated production cost', function (): void {
    $director = purchaseDirector();
    $supplier = purchaseSupplier();
    $material = purchaseRawMaterial(100, 80);

    app(PurchaseService::class)->create(
        purchaseHeader($supplier),
        [[
            'raw_material_id' => $material->id,
            'quantity' => 100,
            'purchase_rate' => 100,
            'gst_percentage' => 18,
        ]],
        $director,
        confirm: true,
    );

    $bulk = SemiFinishedMaterial::query()->create([
        'material_name' => 'Bulk Mix '.uniqid(),
        'unit' => 'Kg',
        'minimum_stock' => 0,
        'status' => true,
    ]);
    $bom = Bom::query()->create([
        'output_type' => BomOutputType::SemiFinished,
        'semi_finished_id' => $bulk->id,
        'product_id' => null,
        'batch_quantity' => 1,
        'batch_unit' => 'Kg',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);
    BomItem::query()->create([
        'bom_id' => $bom->id,
        'item_type' => BomItemType::RawMaterial,
        'raw_material_id' => $material->id,
        'required_quantity' => 1,
        'unit' => 'Kg',
        'sort_order' => 1,
    ]);

    $summary = app(BOMCalculationService::class)->summarizeBom($bom->fresh(), $bom->items()->get());

    expect((float) $material->fresh()->average_rate)->toBe(90.0)
        ->and((float) $summary['estimated_raw_material_cost'])->toBe(90.0)
        ->and((float) $summary['estimated_total_bom_cost'])->toBe(90.0);
});

it('does not post a second purchase inward when confirm is called twice', function (): void {
    $director = purchaseDirector();
    $supplier = purchaseSupplier();
    $material = purchaseRawMaterial(100, 80);

    $purchase = app(PurchaseService::class)->create(
        purchaseHeader($supplier),
        [[
            'raw_material_id' => $material->id,
            'quantity' => 10,
            'purchase_rate' => 80,
            'gst_percentage' => 0,
        ]],
        $director,
        confirm: true,
    );

    expect(fn () => app(PurchaseService::class)->confirm($purchase, $director))
        ->toThrow(ValidationException::class);

    expect(StockLedger::query()
        ->where('reference_id', $purchase->id)
        ->where('transaction_type', StockTransactionType::Purchase)
        ->count())->toBe(1)
        ->and((float) $material->fresh()->current_stock)->toBe(110.0);
});

it('restores weighted average stock value when a confirmed purchase is cancelled', function (): void {
    $director = purchaseDirector();
    $supplier = purchaseSupplier();
    $material = purchaseRawMaterial(100, 80);

    $purchase = app(PurchaseService::class)->create(
        purchaseHeader($supplier),
        [[
            'raw_material_id' => $material->id,
            'quantity' => 100,
            'purchase_rate' => 100,
            'gst_percentage' => 18,
        ]],
        $director,
        confirm: true,
    );

    $inward = StockLedger::query()
        ->where('reference_id', $purchase->id)
        ->where('transaction_type', StockTransactionType::Purchase)
        ->first();

    app(PurchaseService::class)->cancel($purchase, $director, 'Wrong rate');
    $material = $material->fresh();
    $reversal = StockLedger::query()
        ->where('reference_id', $purchase->id)
        ->where('transaction_type', StockTransactionType::PurchaseReturn)
        ->first();

    expect((float) $material->current_stock)->toBe(100.0)
        ->and((float) $material->average_rate)->toBe(80.0)
        ->and((float) $material->current_stock_value)->toBe(8000.0)
        ->and((float) $inward->rate)->toBe(100.0)
        ->and((float) $reversal->rate)->toBe(100.0)
        ->and(StockLedger::query()->where('reference_id', $purchase->id)->where('transaction_type', StockTransactionType::Purchase)->count())->toBe(1);
});
