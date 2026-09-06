<?php

use App\Enums\StockItemType;
use App\Enums\UserRole;
use App\Exports\Inventory\InventoryReportExport;
use App\Filament\Pages\InventoryReports;
use App\Filament\Pages\StockItemLedger;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\User;
use App\Services\Inventory\InventoryReportService;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

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
        ->assertSee('Export PDF')
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

function inventoryReportExportMaterial(string $name, float $stock = 10): RawMaterial
{
    return RawMaterial::query()->create([
        'material_name' => $name,
        'category' => 'General',
        'unit' => 'Kg',
        'opening_stock' => $stock,
        'minimum_stock' => 2,
        'purchase_rate' => 10,
        'average_rate' => 10,
        'status' => true,
    ]);
}

function inventoryReportPdfText(string $pdfBytes): string
{
    $decoded = '';

    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdfBytes, $matches)) {
        foreach ($matches[1] as $stream) {
            $try = @gzuncompress($stream);
            if ($try === false) {
                $try = @gzinflate($stream);
            }
            $decoded .= ($try !== false ? $try : $stream)."\n";
        }
    }

    return (string) preg_replace('/\x00/', '', $decoded);
}

it('exports excel and pdf of the complete inventory stock report when no filters are applied', function (): void {
    Excel::fake();

    inventoryReportExportMaterial('Export Alpha Alloy');
    inventoryReportExportMaterial('Export Beta Alloy');
    PackagingMaterial::query()->create([
        'packaging_name' => 'Export Carton',
        'category' => 'Box',
        'unit' => 'Nos',
        'opening_stock' => 8,
        'minimum_stock' => 2,
        'purchase_rate' => 5,
        'average_rate' => 5,
        'status' => true,
    ]);

    $this->actingAs($this->director);

    $component = Livewire::test(InventoryReports::class)
        ->assertSuccessful()
        ->assertSee('Export PDF')
        ->assertSee('Export Excel');

    expect($component->instance()->hasAppliedExportFilters())->toBeFalse()
        ->and($component->instance()->exportAppliedFiltersLabel())->toBe('None');

    $component->call('exportExcel')->assertHasNoErrors();

    Excel::assertDownloaded(
        'Inventory_Stock_Report_'.now('Asia/Kolkata')->format('Y-m-d').'.xlsx',
        function (InventoryReportExport $export): bool {
            $rows = iterator_to_array($export->generator());
            $names = collect($rows)->pluck(1)->all();

            return $export->appliedFiltersLabel() === 'None'
                && $export->headings() === ['Sr No.', 'Item Name', 'Inventory Type', 'Unit', 'Current Stock', 'Average Rate', 'Stock Value', 'Stock Status']
                && count($rows) === 3
                && in_array('Export Alpha Alloy', $names, true)
                && in_array('Export Beta Alloy', $names, true)
                && in_array('Export Carton', $names, true);
        },
    );

    $pdfView = $component->instance()->exportPdfViewData();
    $html = view('filament.pages.inventory-reports-export-pdf', $pdfView)->render();

    expect($pdfView['title'])->toBe('Inventory Stock Report')
        ->and($pdfView['appliedFiltersLabel'])->toBe('None')
        ->and($pdfView['rows'])->toHaveCount(3)
        ->and(collect($pdfView['columns'])->pluck('label')->all())->toBe([
            'Sr No.', 'Item Name', 'Inventory Type', 'Unit', 'Current Stock', 'Average Rate', 'Stock Value', 'Stock Status',
        ])
        ->and($html)->toContain('Inventory Stock Report')
        ->and($html)->toContain('Generated on:')
        ->and($html)->toContain('Applied Filters: None')
        ->and($html)->toContain('Export Alpha Alloy')
        ->and($html)->toContain('Export Beta Alloy')
        ->and($html)->toContain('Export Carton')
        ->and($html)->toContain('Average Rate')
        ->and($html)->toContain('Current Stock');

    $pdfResponse = $component->instance()->exportPdf();
    $pdfBytes = (string) $pdfResponse->getContent();
    $readable = inventoryReportPdfText($pdfBytes);

    expect($pdfResponse->headers->get('content-type'))->toContain('application/pdf')
        ->and($pdfResponse->headers->get('content-disposition'))->toContain('Inventory_Stock_Report_')
        ->and($pdfResponse->headers->get('content-disposition'))->toContain('.pdf')
        ->and(substr($pdfBytes, 0, 4))->toBe('%PDF')
        ->and($readable)->toContain('Inventory Stock Report')
        ->and($readable)->toContain('Applied Filters: None')
        ->and($readable)->toContain('Export Alpha Alloy');
});

it('exports excel and pdf of all matching filtered rows not only the current page', function (): void {
    Excel::fake();

    foreach (range(1, 12) as $i) {
        inventoryReportExportMaterial(sprintf('Paged Export Item %02d', $i));
    }

    inventoryReportExportMaterial('Unique Filtered Export Item', 25);

    PackagingMaterial::query()->create([
        'packaging_name' => 'Filtered Export Box',
        'category' => 'Box',
        'unit' => 'Nos',
        'opening_stock' => 4,
        'minimum_stock' => 1,
        'purchase_rate' => 3,
        'average_rate' => 3,
        'status' => true,
    ]);

    $this->actingAs($this->director);

    $unfiltered = Livewire::test(InventoryReports::class)
        ->set('tableRecordsPerPage', 10)
        ->assertSuccessful();

    expect($unfiltered->instance()->hasAppliedExportFilters())->toBeFalse();

    $unfiltered->call('exportExcel')->assertHasNoErrors();

    Excel::assertDownloaded(
        'Inventory_Stock_Report_'.now('Asia/Kolkata')->format('Y-m-d').'.xlsx',
        function (InventoryReportExport $export): bool {
            return count(iterator_to_array($export->generator())) === 14;
        },
    );

    Excel::fake();

    $filtered = Livewire::test(InventoryReports::class)
        ->set('tableRecordsPerPage', 10)
        ->set('data.search', 'Unique Filtered Export Item')
        ->call('applyFilters');

    expect($filtered->instance()->hasAppliedExportFilters())->toBeTrue()
        ->and($filtered->instance()->exportAppliedFiltersLabel())->toContain('Search: Unique Filtered Export Item');

    $filtered->call('exportExcel')->assertHasNoErrors();

    Excel::assertDownloaded(
        'Inventory_Stock_Report_'.now('Asia/Kolkata')->format('Y-m-d').'.xlsx',
        function (InventoryReportExport $export): bool {
            $rows = iterator_to_array($export->generator());
            $names = collect($rows)->pluck(1)->all();

            return $export->appliedFiltersLabel() !== 'None'
                && str_contains($export->appliedFiltersLabel(), 'Search: Unique Filtered Export Item')
                && count($rows) === 1
                && $names === ['Unique Filtered Export Item'];
        },
    );

    $pdfView = $filtered->instance()->exportPdfViewData();
    $html = view('filament.pages.inventory-reports-export-pdf', $pdfView)->render();
    $names = collect($pdfView['rows'])->map(fn (array $row): string => (string) ($row[1]['value'] ?? ''))->all();

    expect($pdfView['appliedFiltersLabel'])->toContain('Search: Unique Filtered Export Item')
        ->and($pdfView['rows'])->toHaveCount(1)
        ->and($names)->toBe(['Unique Filtered Export Item'])
        ->and($html)->toContain('Applied Filters:')
        ->and($html)->toContain('Search: Unique Filtered Export Item')
        ->and($html)->toContain('Unique Filtered Export Item')
        ->and($html)->not->toContain('Paged Export Item 01')
        ->and($html)->not->toContain('Filtered Export Box');

    $typeFiltered = Livewire::test(InventoryReports::class)
        ->set('data.inventory_type', InventoryReportService::TYPE_RAW_MATERIAL)
        ->call('applyFilters');

    $typePdf = $typeFiltered->instance()->exportPdfViewData();
    $typeNames = collect($typePdf['rows'])->map(fn (array $row): string => (string) ($row[1]['value'] ?? ''))->all();

    expect($typeFiltered->instance()->hasAppliedExportFilters())->toBeTrue()
        ->and($typePdf['appliedFiltersLabel'])->toContain('Inventory Type: Raw Material')
        ->and($typeNames)->toContain('Unique Filtered Export Item')
        ->and($typeNames)->not->toContain('Filtered Export Box')
        ->and(count($typeNames))->toBe(13);
});
