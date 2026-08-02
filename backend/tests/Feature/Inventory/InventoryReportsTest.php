<?php

use App\Enums\StockItemType;
use App\Enums\UserRole;
use App\Filament\Pages\InventoryReports;
use App\Filament\Pages\StockItemLedger;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\User;
use App\Services\Inventory\InventoryReportService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->director = User::query()->create([
        'name' => 'Reports Director',
        'email' => 'reports.director.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
});

it('denies inventory reports page to regular employees', function (): void {
    $employee = User::query()->create([
        'name' => 'Reports Employee',
        'email' => 'reports.employee.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
        'job_role' => 'Sales Executive',
    ]);

    $this->actingAs($employee);

    expect(InventoryReports::canAccess())->toBeFalse();
});

it('allows directors to access inventory reports', function (): void {
    $this->actingAs($this->director);

    expect(InventoryReports::canAccess())->toBeTrue();
});

it('shows a unified stock report combining raw material, packaging, and finished product rows', function (): void {
    RawMaterial::query()->create([
        'material_name' => 'Alloy A',
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => 12.5,
        'minimum_stock' => 5,
        'purchase_rate' => 100,
        'average_rate' => 100,
        'status' => true,
    ]);

    RawMaterial::query()->create([
        'material_name' => 'Alloy B',
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => 0,
        'minimum_stock' => 2,
        'purchase_rate' => 50,
        'average_rate' => 50,
        'status' => true,
    ]);

    PackagingMaterial::query()->create([
        'packaging_name' => 'Carton Box',
        'category' => 'Box',
        'unit' => 'Nos',
        'opening_stock' => 20,
        'minimum_stock' => 10,
        'purchase_rate' => 15,
        'average_rate' => 15,
        'status' => true,
    ]);

    Product::query()->create([
        'product_name' => 'Finished Widget',
        'category' => 'General',
        'uom' => 'Piece',
        'status' => true,
        'manufacturing_enabled' => true,
        'current_finished_stock' => 30,
        'minimum_finished_stock' => 5,
        'weighted_average_cost' => 200,
    ]);

    $this->actingAs($this->director);

    Livewire::test(InventoryReports::class)
        ->assertSuccessful()
        ->assertSee('Inventory Stock Report')
        ->assertSee('Alloy A')
        ->assertSee('Carton Box')
        ->assertSee('Finished Widget')
        ->assertSee('Out of Stock')
        ->assertSee('View Ledger')
        ->assertSee('Average Rate')
        ->assertSee('Total Stock Value')
        ->assertSee('Raw Material Value')
        ->assertSee('Packaging Material Value')
        ->assertSee('Finished Product Value')
        ->assertSee('Low Stock Items')
        ->assertSee('Out Of Stock Items')
        ->assertDontSee('Report Type')
        ->assertDontSee('Total Items')
        ->assertSee('Apply')
        ->assertSee('Export Excel')
        ->set('tableRecordsPerPage', 10)
        ->call('applyFilters')
        ->assertSee('Alloy A')
        ->assertSeeHtml('fi-ta-ctn');
});

it('builds the unified stock report with combined summary and streamed export rows', function (): void {
    RawMaterial::query()->create([
        'material_name' => 'Copper Wire',
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => 20,
        'minimum_stock' => 5,
        'purchase_rate' => 80,
        'average_rate' => 80,
        'status' => true,
    ]);

    RawMaterial::query()->create([
        'material_name' => 'Flux',
        'category' => 'Chemical',
        'unit' => 'Litre',
        'opening_stock' => 1,
        'minimum_stock' => 5,
        'purchase_rate' => 40,
        'average_rate' => 40,
        'status' => true,
    ]);

    PackagingMaterial::query()->create([
        'packaging_name' => 'Poly Bag',
        'category' => 'Bag',
        'unit' => 'Nos',
        'opening_stock' => 100,
        'minimum_stock' => 20,
        'purchase_rate' => 2,
        'average_rate' => 2,
        'status' => true,
    ]);

    $report = app(InventoryReportService::class)->build([
        'inventory_type' => InventoryReportService::TYPE_ALL,
        'item_key' => null,
        'search' => null,
        'stock_status_filter' => null,
    ]);

    $labels = collect($report->summaryCards)->pluck('label')->all();
    $columnLabels = collect($report->columns)->pluck('label')->all();

    expect($report->title)->toBe('Inventory Stock Report')
        ->and($report->filenameStem)->toBe('Inventory_Stock_Report')
        ->and($labels)->toBe(['Total Stock Value', 'Raw Material Value', 'Packaging Material Value', 'Semi-Finished Value', 'Finished Product Value', 'Low Stock Items', 'Out Of Stock Items'])
        ->and($columnLabels)->toBe(['Sr No.', 'Item Name', 'Inventory Type', 'Unit', 'Current Stock', 'Average Rate', 'Stock Value', 'Stock Status'])
        ->and($report->totalStockValueFooter())->toBeGreaterThan(0);

    $paginator = $report->paginate(25, 'item_name', 'asc');
    expect($paginator->total())->toBe(3);

    $exported = iterator_to_array($report->exportRows());
    expect($exported)->toHaveCount(3)
        ->and($exported[0][0])->toBe(1)
        ->and(count($exported[0]))->toBe(count($report->columns));

    $breakdown = $report->footerBreakdownTotals();
    expect($breakdown)->not->toBeNull()
        ->and($breakdown[InventoryReportService::TYPE_RAW_MATERIAL])->toBeGreaterThan(0)
        ->and($breakdown[InventoryReportService::TYPE_PACKAGING_MATERIAL])->toBeGreaterThan(0)
        ->and($breakdown[InventoryReportService::TYPE_SEMI_FINISHED])->toBe(0.0);
});

it('filters the unified stock report by inventory type', function (): void {
    RawMaterial::query()->create([
        'material_name' => 'Only Raw Material',
        'category' => 'General',
        'unit' => 'Kg',
        'opening_stock' => 10,
        'minimum_stock' => 2,
        'purchase_rate' => 10,
        'average_rate' => 10,
        'status' => true,
    ]);

    PackagingMaterial::query()->create([
        'packaging_name' => 'Only Packaging',
        'category' => 'Box',
        'unit' => 'Nos',
        'opening_stock' => 10,
        'minimum_stock' => 2,
        'purchase_rate' => 5,
        'average_rate' => 5,
        'status' => true,
    ]);

    $report = app(InventoryReportService::class)->build([
        'inventory_type' => InventoryReportService::TYPE_RAW_MATERIAL,
    ]);

    $names = collect($report->paginate(25, 'item_name', 'asc')->items())->pluck('name')->all();

    expect($names)->toContain('Only Raw Material')
        ->and($names)->not->toContain('Only Packaging');
});

it('shows zero records for the semi finished inventory type without fake data', function (): void {
    RawMaterial::query()->create([
        'material_name' => 'Unrelated Material',
        'category' => 'General',
        'unit' => 'Kg',
        'opening_stock' => 10,
        'minimum_stock' => 2,
        'purchase_rate' => 10,
        'average_rate' => 10,
        'status' => true,
    ]);

    $report = app(InventoryReportService::class)->build([
        'inventory_type' => InventoryReportService::TYPE_SEMI_FINISHED,
    ]);

    expect($report->paginate(25, 'item_name', 'asc')->total())->toBe(0);
});

it('filters stock by low stock and out of stock status without changing stored values', function (): void {
    $low = RawMaterial::query()->create([
        'material_name' => 'Low Item',
        'category' => 'General',
        'unit' => 'Nos',
        'opening_stock' => 3,
        'minimum_stock' => 5,
        'purchase_rate' => 10,
        'average_rate' => 10,
        'status' => true,
    ]);

    RawMaterial::query()->create([
        'material_name' => 'Healthy Item',
        'category' => 'General',
        'unit' => 'Nos',
        'opening_stock' => 50,
        'minimum_stock' => 5,
        'purchase_rate' => 10,
        'average_rate' => 10,
        'status' => true,
    ]);

    $out = RawMaterial::query()->create([
        'material_name' => 'Zero Item',
        'category' => 'General',
        'unit' => 'Nos',
        'opening_stock' => 0,
        'minimum_stock' => 5,
        'purchase_rate' => 10,
        'average_rate' => 10,
        'status' => true,
    ]);

    $lowReport = app(InventoryReportService::class)->build([
        'stock_status_filter' => 'low_stock',
    ]);
    $lowNames = collect($lowReport->paginate(25, 'item_name', 'asc')->items())->pluck('name')->all();

    expect($lowNames)->toContain('Low Item')
        ->and($lowNames)->not->toContain('Healthy Item')
        ->and($lowNames)->not->toContain('Zero Item')
        ->and((float) $low->fresh()->current_stock)->toBe(3.0)
        ->and((float) $low->fresh()->average_rate)->toBe(10.0);

    $outReport = app(InventoryReportService::class)->build([
        'stock_status_filter' => 'out_of_stock',
    ]);
    $outNames = collect($outReport->paginate(25, 'item_name', 'asc')->items())->pluck('name')->all();

    expect($outNames)->toContain('Zero Item')
        ->and($outNames)->not->toContain('Healthy Item')
        ->and((float) $out->fresh()->current_stock)->toBe(0.0);
});

it('builds item options scoped to the selected inventory type', function (): void {
    $material = RawMaterial::query()->create([
        'material_name' => 'Scoped Material',
        'category' => 'General',
        'unit' => 'Kg',
        'opening_stock' => 5,
        'minimum_stock' => 1,
        'purchase_rate' => 20,
        'average_rate' => 20,
        'status' => true,
    ]);

    PackagingMaterial::query()->create([
        'packaging_name' => 'Scoped Packaging',
        'category' => 'Box',
        'unit' => 'Nos',
        'opening_stock' => 5,
        'minimum_stock' => 1,
        'purchase_rate' => 5,
        'average_rate' => 5,
        'status' => true,
    ]);

    $service = app(InventoryReportService::class);

    $rawOptions = $service->itemOptions(InventoryReportService::TYPE_RAW_MATERIAL);
    $allOptions = $service->itemOptions(InventoryReportService::TYPE_ALL);
    $semiFinishedOptions = $service->itemOptions(InventoryReportService::TYPE_SEMI_FINISHED);

    $rawOptionHasPackagingKey = collect($rawOptions)->keys()->contains(fn ($k) => str_starts_with((string) $k, 'packaging_material:'));

    expect($rawOptions)->toHaveKey(InventoryReportService::TYPE_RAW_MATERIAL.':'.$material->id)
        ->and($rawOptionHasPackagingKey)->toBeFalse()
        ->and($allOptions)->toHaveKey(InventoryReportService::TYPE_RAW_MATERIAL.':'.$material->id)
        ->and($semiFinishedOptions)->toBe([]);
});

it('builds view ledger urls for unified stock report items', function (): void {
    $material = RawMaterial::query()->create([
        'material_name' => 'Ledger Link Alloy',
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => 5,
        'minimum_stock' => 1,
        'purchase_rate' => 20,
        'average_rate' => 20,
        'status' => true,
    ]);

    $url = StockItemLedger::urlForItem(
        StockItemType::RawMaterial->value,
        $material->id,
    );

    expect($url)->toContain('/admin/inventory-reports/ledger/raw-material/'.$material->id)
        ->and(StockItemLedger::shouldRegisterNavigation())->toBeFalse();
});

it('applies inventory type and stock status filters from summary cards without requiring Apply', function (): void {
    RawMaterial::query()->create([
        'material_name' => 'Card Raw Healthy',
        'category' => 'General',
        'unit' => 'Kg',
        'opening_stock' => 50,
        'minimum_stock' => 5,
        'purchase_rate' => 10,
        'average_rate' => 10,
        'status' => true,
    ]);

    RawMaterial::query()->create([
        'material_name' => 'Card Raw Low',
        'category' => 'General',
        'unit' => 'Kg',
        'opening_stock' => 2,
        'minimum_stock' => 5,
        'purchase_rate' => 10,
        'average_rate' => 10,
        'status' => true,
    ]);

    PackagingMaterial::query()->create([
        'packaging_name' => 'Card Packaging',
        'category' => 'Box',
        'unit' => 'Nos',
        'opening_stock' => 20,
        'minimum_stock' => 5,
        'purchase_rate' => 3,
        'average_rate' => 3,
        'status' => true,
    ]);

    $this->actingAs($this->director);

    $component = Livewire::test(InventoryReports::class)
        ->assertSuccessful()
        ->call('filterRawMaterialStock')
        ->assertSet('data.inventory_type', InventoryReportService::TYPE_RAW_MATERIAL)
        ->assertSet('data.item_key', null)
        ->assertSet('data.search', null)
        ->assertSet('stockStatusFilter', null)
        ->assertSet('urlInventoryType', InventoryReportService::TYPE_RAW_MATERIAL);

    $rawNames = collect(
        app(InventoryReportService::class)
            ->build($component->instance()->activeFilters())
            ->paginate(25, 'item_name', 'asc')
            ->items()
    )->pluck('name')->all();

    expect($rawNames)->toContain('Card Raw Healthy')
        ->and($rawNames)->toContain('Card Raw Low')
        ->and($rawNames)->not->toContain('Card Packaging')
        ->and($component->instance()->activeFilters()['inventory_type'])->toBe(InventoryReportService::TYPE_RAW_MATERIAL)
        ->and($component->instance()->isSummaryCardActive(InventoryReportService::TYPE_RAW_MATERIAL))->toBeTrue()
        ->and($component->instance()->isSummaryCardActive('total'))->toBeFalse();

    $labels = app(InventoryReportService::class)->build($component->instance()->activeFilters())->appliedFilterLabels;
    expect($labels)->toContain('Inventory Type: Raw Material');

    $component
        ->call('filterLowStock')
        ->assertSet('data.inventory_type', InventoryReportService::TYPE_RAW_MATERIAL)
        ->assertSet('stockStatusFilter', 'low_stock');

    $lowNames = collect(
        app(InventoryReportService::class)
            ->build($component->instance()->activeFilters())
            ->paginate(25, 'item_name', 'asc')
            ->items()
    )->pluck('name')->all();

    expect($lowNames)->toBe(['Card Raw Low'])
        ->and($component->instance()->isSummaryCardActive(InventoryReportService::TYPE_RAW_MATERIAL))->toBeTrue()
        ->and($component->instance()->isSummaryCardActive('low_stock'))->toBeTrue();

    $labels = app(InventoryReportService::class)->build($component->instance()->activeFilters())->appliedFilterLabels;
    expect($labels)->toContain('Stock Status: Low Stock');

    $component
        ->call('filterPackagingMaterialStock')
        ->assertSet('data.inventory_type', InventoryReportService::TYPE_PACKAGING_MATERIAL)
        ->assertSet('stockStatusFilter', null);

    $packNames = collect(
        app(InventoryReportService::class)
            ->build($component->instance()->activeFilters())
            ->paginate(25, 'item_name', 'asc')
            ->items()
    )->pluck('name')->all();

    expect($packNames)->toBe(['Card Packaging']);

    $component
        ->call('filterTotalStock')
        ->assertSet('data.inventory_type', InventoryReportService::TYPE_ALL)
        ->assertSet('stockStatusFilter', null);

    expect($component->instance()->isSummaryCardActive('total'))->toBeTrue();

    $component
        ->call('filterOutOfStock')
        ->assertSet('stockStatusFilter', 'out_of_stock')
        ->assertSet('data.inventory_type', InventoryReportService::TYPE_ALL);

    $component
        ->call('resetFilters')
        ->assertSet('data.inventory_type', InventoryReportService::TYPE_ALL)
        ->assertSet('data.item_key', null)
        ->assertSet('data.search', null)
        ->assertSet('stockStatusFilter', null)
        ->assertSet('urlInventoryType', InventoryReportService::TYPE_ALL)
        ->assertSet('urlItemId', null)
        ->assertSet('urlSearch', null);

    expect($component->instance()->isSummaryCardActive('total'))->toBeTrue()
        ->and($component->instance()->isSummaryCardActive('low_stock'))->toBeFalse();
});
