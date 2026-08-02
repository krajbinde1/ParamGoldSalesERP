<?php

use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Exports\Inventory\StockItemLedgerExport;
use App\Filament\Pages\InventoryReports;
use App\Filament\Pages\StockItemLedger;
use App\Models\RawMaterial;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\StockItemLedgerService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function (): void {
    $this->director = User::query()->create([
        'name' => 'Ledger Director',
        'email' => 'ledger.director.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
});

function seedLedgerMaterial(float $openingStock = 0, float $avg = 50): RawMaterial
{
    return RawMaterial::query()->create([
        'material_name' => 'Ledger Alloy '.uniqid(),
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => $openingStock,
        'minimum_stock' => 1,
        'purchase_rate' => $avg,
        'average_rate' => $avg,
        'status' => true,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function postLedgerRow(RawMaterial $material, array $overrides = []): StockLedger
{
    $qtyIn = (float) ($overrides['quantity_in'] ?? 0);
    $qtyOut = (float) ($overrides['quantity_out'] ?? 0);
    $rate = (float) ($overrides['rate'] ?? 50);
    $stockBefore = (float) ($overrides['stock_before'] ?? 0);
    $stockAfter = (float) ($overrides['stock_after'] ?? ($stockBefore + $qtyIn - $qtyOut));
    $avgBefore = (float) ($overrides['average_rate_before'] ?? ($qtyOut > 0 ? $rate : 50));
    $inwardValue = (float) ($overrides['inward_value'] ?? ($qtyIn > 0 ? round($qtyIn * $rate, 2) : 0));
    $outwardValue = (float) ($overrides['outward_value'] ?? ($qtyOut > 0 ? round($qtyOut * $avgBefore, 2) : 0));
    $openingValue = (float) ($overrides['opening_value'] ?? round($stockBefore * $avgBefore, 2));
    $closingValue = (float) ($overrides['closing_value'] ?? max(0, round($openingValue + $inwardValue - $outwardValue, 2)));
    $avgAfter = (float) ($overrides['average_rate_after'] ?? ($stockAfter > 0 ? round($closingValue / $stockAfter, 4) : 0));

    return StockLedger::query()->create(array_merge([
        'transaction_date' => now()->toDateString(),
        'transaction_type' => StockTransactionType::RawMaterialInward->value,
        'item_type' => StockItemType::RawMaterial->value,
        'raw_material_id' => $material->id,
        'reference_number' => 'RMI-TEST-'.uniqid(),
        'quantity_in' => $qtyIn,
        'quantity_out' => $qtyOut,
        'stock_before' => $stockBefore,
        'stock_after' => $stockAfter,
        'rate' => $rate,
        'old_average_rate' => $avgBefore,
        'new_average_rate' => $avgAfter,
        'average_rate_before' => $avgBefore,
        'average_rate_after' => $avgAfter,
        'opening_value' => $openingValue,
        'closing_value' => $closingValue,
        'transaction_value' => max($qtyIn, $qtyOut) * $rate,
        'inward_value' => $inwardValue,
        'outward_value' => $outwardValue,
        'remarks' => 'Test ledger row',
    ], $overrides));
}

it('requires an item filter to build the item stock ledger', function (): void {
    $service = app(StockItemLedgerService::class);

    expect(fn () => $service->build([
        'item_type' => StockItemType::RawMaterial->value,
        'from' => now()->subDays(7)->toDateString(),
        'to' => now()->toDateString(),
    ], requireItem: true))->toThrow(ValidationException::class);
});

it('computes opening balance from transactions before from date', function (): void {
    $material = seedLedgerMaterial();

    postLedgerRow($material, [
        'transaction_date' => now()->subDays(10)->toDateString(),
        'quantity_in' => 100,
        'quantity_out' => 0,
        'stock_before' => 0,
        'stock_after' => 100,
        'rate' => 40,
        'average_rate_before' => 0,
        'average_rate_after' => 40,
        'opening_value' => 0,
        'closing_value' => 4000,
        'inward_value' => 4000,
        'outward_value' => 0,
        'created_at' => now()->subDays(10),
    ]);

    postLedgerRow($material, [
        'transaction_date' => now()->subDays(8)->toDateString(),
        'transaction_type' => StockTransactionType::ProductionConsumption->value,
        'quantity_in' => 0,
        'quantity_out' => 20,
        'stock_before' => 100,
        'stock_after' => 80,
        'rate' => 40,
        'average_rate_before' => 40,
        'average_rate_after' => 40,
        'opening_value' => 4000,
        'closing_value' => 3200,
        'inward_value' => 0,
        'outward_value' => 800,
        'created_at' => now()->subDays(8),
    ]);

    postLedgerRow($material, [
        'transaction_date' => now()->toDateString(),
        'quantity_in' => 10,
        'quantity_out' => 0,
        'stock_before' => 80,
        'stock_after' => 90,
        'rate' => 50,
        'average_rate_before' => 40,
        'average_rate_after' => 41.1111,
        'opening_value' => 3200,
        'closing_value' => 3700,
        'inward_value' => 500,
        'outward_value' => 0,
    ]);

    $from = now()->subDays(5)->toDateString();
    $opening = app(StockItemLedgerService::class)->computeOpeningBalance(
        StockItemType::RawMaterial,
        $material->id,
        $from,
    );

    expect($opening['qty'])->toBe(80.0)
        ->and($opening['value'])->toBe(3200.0)
        ->and($opening['rate'])->toBe(40.0);
});

it('applies running WAC for inward then outward using historical rates', function (): void {
    $material = seedLedgerMaterial();
    $day1 = now()->subDays(2)->toDateString();
    $day2 = now()->subDay()->toDateString();

    postLedgerRow($material, [
        'transaction_date' => $day1,
        'quantity_in' => 100,
        'quantity_out' => 0,
        'stock_before' => 0,
        'stock_after' => 100,
        'rate' => 50,
        'average_rate_before' => 0,
        'average_rate_after' => 50,
        'opening_value' => 0,
        'closing_value' => 5000,
        'inward_value' => 5000,
        'outward_value' => 0,
        'created_at' => now()->subDays(2),
    ]);

    postLedgerRow($material, [
        'transaction_date' => $day2,
        'transaction_type' => StockTransactionType::ProductionConsumption->value,
        'quantity_in' => 0,
        'quantity_out' => 40,
        'stock_before' => 100,
        'stock_after' => 60,
        'rate' => 50,
        'average_rate_before' => 50,
        'average_rate_after' => 50,
        'opening_value' => 5000,
        'closing_value' => 3000,
        'inward_value' => 0,
        'outward_value' => 2000,
        'created_at' => now()->subDay(),
    ]);

    // Master rate changed later — must not affect historical outward valuation.
    $material->update(['average_rate' => 999]);

    $result = app(StockItemLedgerService::class)->build([
        'item_type' => StockItemType::RawMaterial->value,
        'item_id' => $material->id,
        'from' => $day1,
        'to' => $day2,
        'page' => 1,
        'per_page' => 50,
    ]);

    expect($result->header['opening_qty'])->toBe(0.0)
        ->and($result->rows)->toHaveCount(2)
        ->and($result->rows[0]['inward_qty'])->toBe(100.0)
        ->and($result->rows[0]['closing_qty'])->toBe(100.0)
        ->and($result->rows[0]['closing_value'])->toBe(5000.0)
        ->and($result->rows[1]['outward_qty'])->toBe(40.0)
        ->and($result->rows[1]['outward_rate'])->toBe(50.0)
        ->and($result->rows[1]['outward_value'])->toBe(2000.0)
        ->and($result->rows[1]['closing_qty'])->toBe(60.0)
        ->and($result->rows[1]['closing_value'])->toBe(3000.0)
        ->and($result->totals['closing_qty'])->toBe(60.0)
        ->and($result->totals['closing_value'])->toBe(3000.0);
});

it('zeros value and rate when stock reaches zero', function (): void {
    $material = seedLedgerMaterial();
    $day = now()->toDateString();

    postLedgerRow($material, [
        'transaction_date' => $day,
        'quantity_in' => 25,
        'quantity_out' => 0,
        'stock_before' => 0,
        'stock_after' => 25,
        'rate' => 20,
        'average_rate_before' => 0,
        'average_rate_after' => 20,
        'opening_value' => 0,
        'closing_value' => 500,
        'inward_value' => 500,
        'created_at' => now()->subMinute(),
    ]);

    postLedgerRow($material, [
        'transaction_date' => $day,
        'transaction_type' => StockTransactionType::Damage->value,
        'quantity_in' => 0,
        'quantity_out' => 25,
        'stock_before' => 25,
        'stock_after' => 0,
        'rate' => 20,
        'average_rate_before' => 20,
        'average_rate_after' => 0,
        'opening_value' => 500,
        'closing_value' => 0,
        'outward_value' => 500,
        'remarks' => 'Damaged in transit',
        'created_at' => now(),
    ]);

    $result = app(StockItemLedgerService::class)->build([
        'item_type' => StockItemType::RawMaterial->value,
        'item_id' => $material->id,
        'from' => $day,
        'to' => $day,
    ]);

    $last = $result->rows[1];

    expect($last['closing_qty'])->toBe(0.0)
        ->and($last['closing_value'])->toBe(0.0)
        ->and($last['closing_rate'])->toBe(0.0)
        ->and($last['particulars'])->toBe('Damage – Damaged in transit');
});

it('streams export rows including opening and totals', function (): void {
    Excel::fake();

    $material = seedLedgerMaterial();
    $from = now()->subDay()->toDateString();
    $to = now()->toDateString();

    postLedgerRow($material, [
        'transaction_date' => $to,
        'quantity_in' => 15,
        'quantity_out' => 0,
        'stock_before' => 0,
        'stock_after' => 15,
        'rate' => 30,
        'average_rate_before' => 0,
        'average_rate_after' => 30,
        'opening_value' => 0,
        'closing_value' => 450,
        'inward_value' => 450,
    ]);

    $filters = [
        'item_type' => StockItemType::RawMaterial->value,
        'item_id' => $material->id,
        'from' => $from,
        'to' => $to,
    ];

    $service = app(StockItemLedgerService::class);
    $summary = $service->build([...$filters, 'page' => 1, 'per_page' => 1]);
    $streamed = iterator_to_array($service->streamRows($filters), false);

    expect($streamed[0]['row_type'])->toBe('opening')
        ->and($streamed[0]['particulars'])->toBe('Opening Balance')
        ->and($streamed)->toHaveCount(2)
        ->and($summary->totals['total_inward_qty'])->toBe(15.0);

    $export = new StockItemLedgerExport(
        filters: $filters,
        summary: $summary,
        companyName: 'Param Gold Sales ERP',
    );
    $exportRows = iterator_to_array($export->generator(), false);

    expect($exportRows[array_key_last($exportRows)][1])->toBe('Closing Balance')
        ->and($exportRows[array_key_last($exportRows)][3])->toBe(15.0);

    $this->actingAs($this->director);

    $defaults = StockItemLedger::defaultDateRange();

    $expectedName = sprintf(
        'Stock_Ledger_%s_%s_to_%s.xlsx',
        \Illuminate\Support\Str::slug($material->material_code),
        $defaults['from'],
        $defaults['to'],
    );

    Livewire::test(StockItemLedger::class, [
        'itemType' => 'raw-material',
        'itemId' => $material->id,
    ])
        ->call('exportExcel')
        ->assertHasNoErrors();

    Excel::assertDownloaded($expectedName);
});

it('redirects bare stock ledger page to inventory reports', function (): void {
    $this->actingAs($this->director);

    Livewire::test(StockItemLedger::class)
        ->assertRedirect(InventoryReports::getUrl());

    expect(StockItemLedger::shouldRegisterNavigation())->toBeFalse();
});

it('prefills and shows ledger when opened from stock report item link', function (): void {
    $material = seedLedgerMaterial();

    $this->actingAs($this->director);

    Livewire::test(StockItemLedger::class, [
        'itemType' => 'raw-material',
        'itemId' => $material->id,
    ])
        ->assertSuccessful()
        ->assertSet('itemLocked', true)
        ->assertSet('applied', true)
        ->assertSet('data.item_id', $material->id)
        ->assertSee($material->material_name)
        ->assertSee('Stock Ledger')
        ->assertSee('Code : '.$material->material_code)
        ->assertSee('Unit : Kg')
        ->assertSee('From Date')
        ->assertSee('To Date')
        ->assertSee('Apply Filter')
        ->assertSee('Opening Balance')
        ->assertSee('Closing Balance')
        ->assertSee('Back to Stock Report')
        ->assertSee('Export Excel')
        ->assertSee('Print')
        ->assertDontSee('Select Item')
        ->assertDontSee('Reset')
        ->assertDontSee('Current Stock Position')
        ->assertDontSee('Main Warehouse')
        ->assertDontSee('Voucher Type')
        ->assertDontSee('Inward Rate')
        ->assertDontSee('Outward Rate')
        ->assertDontSee('Reserved Stock')
        ->assertDontSee('Available Stock');
});

it('recalculates opening and closing when date filters are applied', function (): void {
    $material = seedLedgerMaterial();
    $day1 = now()->subDays(3)->toDateString();
    $day2 = now()->toDateString();

    postLedgerRow($material, [
        'transaction_date' => $day1,
        'quantity_in' => 100,
        'quantity_out' => 0,
        'stock_before' => 0,
        'stock_after' => 100,
        'rate' => 40,
        'average_rate_before' => 0,
        'average_rate_after' => 40,
        'opening_value' => 0,
        'closing_value' => 4000,
        'inward_value' => 4000,
        'created_at' => now()->subDays(3),
    ]);

    postLedgerRow($material, [
        'transaction_date' => $day2,
        'quantity_in' => 20,
        'quantity_out' => 0,
        'stock_before' => 100,
        'stock_after' => 120,
        'rate' => 50,
        'average_rate_before' => 40,
        'average_rate_after' => 41.6667,
        'opening_value' => 4000,
        'closing_value' => 5000,
        'inward_value' => 1000,
        'created_at' => now(),
    ]);

    $this->actingAs($this->director);

    $component = Livewire::test(StockItemLedger::class, [
        'itemType' => 'raw-material',
        'itemId' => $material->id,
    ])
        ->set('data.from', $day2)
        ->set('data.to', $day2)
        ->call('applyFilters')
        ->assertSuccessful()
        ->assertSee('Opening Balance')
        ->assertSee('Closing Balance')
        ->assertSee('100.000') // opening qty for period starting day2
        ->assertSee('20.000'); // period inward qty

    $result = $component->instance()->ledgerResult();

    expect($result->header['opening_qty'])->toBe(100.0)
        ->and($result->header['opening_value'])->toBe(4000.0)
        ->and($result->totals['total_inward_qty'])->toBe(20.0)
        ->and($result->totals['total_inward_value'])->toBe(1000.0)
        ->and($result->totals['closing_qty'])->toBe(120.0)
        ->and($result->totals['closing_value'])->toBe(5000.0)
        ->and($result->totals['closing_rate'])->toBe(41.6667);
});
