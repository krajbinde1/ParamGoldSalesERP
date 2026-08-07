<?php

use App\Enums\BomStatus;
use App\Enums\InventoryBulkImportType;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Exports\Inventory\InventoryBulkImportErrorReportExport;
use App\Exports\Inventory\InventoryBulkImportTemplateExport;
use App\Exports\Inventory\InventoryCodeMappingExport;
use App\Models\Bom;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\BulkImport\InventoryBulkImportManager;
use App\Services\Inventory\BulkImport\InventoryBulkImportReadiness;
use App\Services\Inventory\BulkImport\InventoryBulkImportTemplate;
use App\Services\Inventory\FinishedProductCreateService;
use App\Services\Inventory\RawMaterialCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->director = User::query()->create([
        'name' => 'Bulk Import Director',
        'email' => 'bulk.import.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);

    $this->manager = app(InventoryBulkImportManager::class);
});

function inventoryImportCsv(InventoryBulkImportType $type, array $dataRows): string
{
    $columns = InventoryBulkImportTemplate::allColumns($type);
    $lines = [implode(',', $columns)];

    foreach ($dataRows as $row) {
        $values = [];
        foreach ($columns as $column) {
            $values[] = (string) ($row[$column] ?? '');
        }
        $lines[] = implode(',', $values);
    }

    $path = storage_path('framework/testing/inventory-import-'.uniqid().'.csv');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, implode("\n", $lines));

    return $path;
}

it('generates templates with sample rows for all inventory import modules', function (): void {
    foreach (InventoryBulkImportType::cases() as $type) {
        $rows = (new InventoryBulkImportTemplateExport($type))->array();

        expect($rows[0])->toContain('MANDATORY')
            ->and($rows[1][0])->toBe('Column')
            ->and($rows[2][0])->toBe('Example');
    }

    $rmColumns = InventoryBulkImportTemplate::allColumns(InventoryBulkImportType::RawMaterial);
    expect($rmColumns)->not->toContain('material_code');

    $bomColumns = InventoryBulkImportTemplate::allColumns(InventoryBulkImportType::Bom);
    expect($bomColumns)->toContain('finished_product_code')
        ->and($bomColumns)->toContain('material_code')
        ->and($bomColumns)->toContain('material_name');
});

it('previews and imports raw materials with auto-generated codes and mapping export', function (): void {
    $path = inventoryImportCsv(InventoryBulkImportType::RawMaterial, [
        [
            'material_name' => 'Ferrous Sulphate',
            'unit' => 'Kg',
            'minimum_stock' => '5',
            'opening_quantity' => '0',
            'opening_value' => '0',
            'active' => 'Yes',
        ],
        [
            'material_name' => 'RM With Opening',
            'unit' => 'Kg',
            'minimum_stock' => '10',
            'opening_quantity' => '100',
            'opening_value' => '5000',
            'opening_date' => now('Asia/Kolkata')->toDateString(),
            'batch_tracking' => 'No',
            'expiry_tracking' => 'No',
            'active' => 'Yes',
            'remarks' => 'Migration',
        ],
    ]);

    $preview = $this->manager->preview($path, InventoryBulkImportType::RawMaterial);
    expect($preview->counts['total'])->toBe(2)
        ->and($preview->counts['valid'])->toBe(2)
        ->and($preview->counts['to_import'])->toBe(2);

    $result = $this->manager->import($path, InventoryBulkImportType::RawMaterial, $this->director);

    expect($result->imported)->toBe(2)
        ->and($result->failed)->toBe(0)
        ->and($result->openingLedgerCreated)->toBe(1)
        ->and($result->stockUpdated)->toBe(1)
        ->and(RawMaterial::query()->count())->toBe(2)
        ->and($result->mappings)->toHaveCount(2);

    $ferrous = RawMaterial::query()->where('material_name', 'Ferrous Sulphate')->first();
    expect($ferrous)->not->toBeNull()
        ->and($ferrous->material_code)->toStartWith('RM')
        ->and($result->mappings[0]['material_code'])->toBe($ferrous->material_code);

    $mappingExport = new InventoryCodeMappingExport(InventoryBulkImportType::RawMaterial, $result->mappings);
    expect($mappingExport->headings())->toContain('Material Code')
        ->and($mappingExport->array()[0])->toContain($ferrous->material_code);

    $withOpening = RawMaterial::query()->where('material_name', 'RM With Opening')->first();
    expect((float) $withOpening->current_stock)->toBe(100.0)
        ->and((float) $withOpening->average_rate)->toBe(50.0)
        ->and((float) $withOpening->current_stock_value)->toBe(5000.0);

    $ledger = StockLedger::query()
        ->where('raw_material_id', $withOpening->id)
        ->where('transaction_type', StockTransactionType::OpeningStock->value)
        ->first();

    expect($ledger)->not->toBeNull()
        ->and($ledger->remarks)->toBe('Bulk Import');

    $zero = RawMaterial::query()->where('material_name', 'Ferrous Sulphate')->first();
    expect(StockLedger::query()->where('raw_material_id', $zero->id)->count())->toBe(0);
});

it('imports packaging and semi-finished masters via shared create services with existing prefixes', function (): void {
    $packPath = inventoryImportCsv(InventoryBulkImportType::PackagingMaterial, [[
        'material_name' => 'Carton Import',
        'unit' => 'Nos',
        'opening_quantity' => '20',
        'opening_value' => '400',
        'opening_date' => now('Asia/Kolkata')->toDateString(),
        'active' => 'Yes',
    ]]);

    $sfPath = inventoryImportCsv(InventoryBulkImportType::SemiFinished, [[
        'material_name' => 'Premix Import',
        'unit' => 'Kg',
        'opening_quantity' => '0',
        'opening_value' => '0',
        'active' => 'Yes',
    ]]);

    $packResult = $this->manager->import($packPath, InventoryBulkImportType::PackagingMaterial, $this->director);
    $sfResult = $this->manager->import($sfPath, InventoryBulkImportType::SemiFinished, $this->director);

    $pack = PackagingMaterial::query()->where('packaging_name', 'Carton Import')->first();
    $sf = SemiFinishedMaterial::query()->where('material_name', 'Premix Import')->first();

    expect($packResult->imported)->toBe(1)
        ->and($sfResult->imported)->toBe(1)
        ->and($pack)->not->toBeNull()
        ->and($pack->packaging_code)->toStartWith('PK')
        ->and($sf)->not->toBeNull()
        ->and($sf->material_code)->toStartWith('SFM');
});

it('links finished product to existing product without creating a duplicate and assigns FP code', function (): void {
    $sales = Product::query()->create([
        'product_name' => 'Sales FG Link Target',
        'category' => 'General',
        'uom' => 'Piece',
        'dealer_price' => 120,
        'gst_percentage' => 12,
        'status' => true,
        'manufacturing_enabled' => false,
        'current_finished_stock' => 0,
    ]);

    $salesCode = $sales->product_code;
    $beforeCount = Product::query()->count();

    $path = inventoryImportCsv(InventoryBulkImportType::FinishedProduct, [[
        'existing_product' => $sales->product_code,
        'minimum_stock' => '5',
        'opening_quantity' => '40',
        'opening_value' => '800',
        'opening_date' => now('Asia/Kolkata')->toDateString(),
        'active' => 'Yes',
    ]]);

    $result = $this->manager->import($path, InventoryBulkImportType::FinishedProduct, $this->director);
    $sales->refresh();

    $sales->load('finishedProduct');

    expect($result->imported)->toBe(1)
        ->and(Product::query()->count())->toBe($beforeCount)
        ->and($sales->manufacturing_enabled)->toBeTrue()
        ->and($sales->product_code)->toBe($salesCode)
        ->and($sales->finishedProduct)->not->toBeNull()
        ->and($sales->finishedProduct->finished_product_code)->toStartWith('FP')
        ->and((float) $sales->current_finished_stock)->toBe(40.0)
        ->and((float) $sales->weighted_average_cost)->toBe(20.0)
        ->and((float) $sales->dealer_price)->toBe(120.0)
        ->and($result->mappings[0]['finished_product_code'])->toBe($sales->finishedProduct->finished_product_code);
});

it('rejects finished product import when product mapping is missing', function (): void {
    $path = inventoryImportCsv(InventoryBulkImportType::FinishedProduct, [[
        'existing_product' => 'DOES-NOT-EXIST',
        'opening_quantity' => '0',
        'opening_value' => '0',
        'active' => 'Yes',
    ]]);

    $preview = $this->manager->preview($path, InventoryBulkImportType::FinishedProduct);

    expect($preview->counts['invalid'])->toBe(1)
        ->and($preview->rows[0]['error'])->toContain('Existing Product not found');
});

it('blocks bom import until finished products and component masters exist', function (): void {
    $readiness = app(InventoryBulkImportReadiness::class)->snapshot();
    expect($readiness['bom_ready'])->toBeFalse();

    $path = inventoryImportCsv(InventoryBulkImportType::Bom, [[
        'finished_product_code' => 'FP000001',
        'material_type' => 'Raw Material',
        'material_code' => 'RM000001',
        'material_name' => 'Anything',
        'quantity' => '1',
        'unit' => 'Kg',
    ]]);

    expect(fn () => $this->manager->preview($path, InventoryBulkImportType::Bom))
        ->toThrow(ValidationException::class);
});

it('imports bom by finished product code and material code; name mismatch warns; invalid code rejects', function (): void {
    $sales = Product::query()->create([
        'product_name' => 'BOM FG Output',
        'category' => 'General',
        'uom' => 'Nos',
        'status' => true,
        'manufacturing_enabled' => false,
    ]);

    $product = app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $sales->id,
            'unit' => 'Nos',
            'minimum_finished_stock' => 0,
            'status' => true,
        ],
        opening: ['quantity' => 0, 'value' => 0],
        user: $this->director,
    );
    $product->load('finishedProduct');
    $fpCode = $product->finishedProduct->finished_product_code;

    $rm = app(RawMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Ferrous Sulphate',
            'unit' => 'Kg',
            'minimum_stock' => 0,
            'status' => true,
        ],
        opening: ['quantity' => 0, 'value' => 0],
        user: $this->director,
    );

    $pack = PackagingMaterial::query()->create([
        'packaging_name' => 'BOM Pack A',
        'category' => 'Other',
        'unit' => 'Nos',
        'minimum_stock' => 0,
        'status' => true,
        'created_by' => $this->director->id,
        'opening_stock' => 0,
        'current_stock' => 0,
        'current_stock_value' => 0,
        'purchase_rate' => 0,
        'average_rate' => 0,
    ]);

    $path = inventoryImportCsv(InventoryBulkImportType::Bom, [
        [
            'finished_product_code' => $fpCode,
            'material_type' => 'Raw Material',
            'material_code' => $rm->material_code,
            'material_name' => 'Wrong Spelling Sulphate',
            'quantity' => '1.5',
            'unit' => 'Kg',
        ],
        [
            'finished_product_code' => $fpCode,
            'material_type' => 'Packaging Material',
            'material_code' => $pack->packaging_code,
            'material_name' => 'BOM Pack A',
            'quantity' => '2',
            'unit' => 'Nos',
        ],
        [
            'finished_product_code' => $fpCode,
            'material_type' => 'Raw Material',
            'material_code' => 'RM999999',
            'material_name' => 'Ghost Material',
            'quantity' => '1',
            'unit' => 'Kg',
        ],
    ]);

    $preview = $this->manager->preview($path, InventoryBulkImportType::Bom);
    $warningRow = collect($preview->rows)->first(
        fn (array $row): bool => ($row['data']['material_code'] ?? '') === $rm->material_code
    );
    $invalidRow = collect($preview->rows)->first(
        fn (array $row): bool => ($row['data']['material_code'] ?? '') === 'RM999999'
    );

    expect($warningRow['is_valid'])->toBeTrue()
        ->and($warningRow['warning'])->toContain('Material Name mismatch')
        ->and($warningRow['warning'])->toContain('Ferrous Sulphate')
        ->and($invalidRow['is_valid'])->toBeFalse()
        ->and($invalidRow['error'])->toBe('Material Code not found in the selected Material Type.');

    // Import only the two valid rows (invalid code causes whole FG group to fail in current importer).
    // Re-import a clean valid file after preview assertions.
    $validPath = inventoryImportCsv(InventoryBulkImportType::Bom, [
        [
            'finished_product_code' => $fpCode,
            'material_type' => 'Raw Material',
            'material_code' => $rm->material_code,
            'material_name' => 'Wrong Spelling Sulphate',
            'quantity' => '1.5',
            'unit' => 'Kg',
        ],
        [
            'finished_product_code' => $fpCode,
            'material_type' => 'Packaging Material',
            'material_code' => $pack->packaging_code,
            'material_name' => 'BOM Pack A',
            'quantity' => '2',
            'unit' => 'Nos',
        ],
    ]);

    $result = $this->manager->import($validPath, InventoryBulkImportType::Bom, $this->director);
    $bom = Bom::query()->with('items')->where('product_id', $product->id)->first();

    expect($result->imported)->toBe(2)
        ->and($result->failed)->toBe(0)
        ->and($bom)->not->toBeNull()
        ->and($bom->status)->toBe(BomStatus::Active)
        ->and($bom->items)->toHaveCount(2)
        ->and($bom->notes)->toBe('Bulk Import')
        ->and(RawMaterial::query()->where('material_name', 'Wrong Spelling Sulphate')->exists())->toBeFalse()
        ->and(RawMaterial::query()->where('material_name', 'Ferrous Sulphate')->count())->toBe(1);
});

it('rejects bom when finished product code is missing', function (): void {
    app(RawMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Some RM',
            'unit' => 'Kg',
            'minimum_stock' => 0,
            'status' => true,
        ],
        opening: ['quantity' => 0, 'value' => 0],
        user: $this->director,
    );

    $someFg = Product::query()->create([
        'product_name' => 'Some FG',
        'category' => 'General',
        'uom' => 'Nos',
        'status' => true,
        'manufacturing_enabled' => false,
    ]);

    app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $someFg->id,
            'unit' => 'Nos',
            'minimum_finished_stock' => 0,
            'status' => true,
        ],
        opening: ['quantity' => 0, 'value' => 0],
        user: $this->director,
    );

    $path = inventoryImportCsv(InventoryBulkImportType::Bom, [[
        'finished_product_code' => 'FP999999',
        'material_type' => 'Raw Material',
        'material_code' => RawMaterial::query()->first()->material_code,
        'material_name' => 'Some RM',
        'quantity' => '1',
        'unit' => 'Kg',
    ]]);

    $preview = $this->manager->preview($path, InventoryBulkImportType::Bom);

    expect($preview->rows[0]['error'])->toBe('Finished Product Code not found.');
});

it('flags duplicate excel rows and existing database names', function (): void {
    app(RawMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Existing RM',
            'unit' => 'Kg',
            'minimum_stock' => 0,
            'status' => true,
        ],
        opening: ['quantity' => 0, 'value' => 0],
        user: $this->director,
    );

    $path = inventoryImportCsv(InventoryBulkImportType::RawMaterial, [
        [
            'material_name' => 'Existing RM',
            'unit' => 'Kg',
            'opening_quantity' => '0',
            'opening_value' => '0',
            'active' => 'Yes',
        ],
        [
            'material_name' => 'Dup In File',
            'unit' => 'Kg',
            'opening_quantity' => '0',
            'opening_value' => '0',
            'active' => 'Yes',
        ],
        [
            'material_name' => 'Dup In File',
            'unit' => 'Kg',
            'opening_quantity' => '0',
            'opening_value' => '0',
            'active' => 'Yes',
        ],
        [
            'material_name' => 'Valid New RM',
            'unit' => 'Kg',
            'opening_quantity' => '0',
            'opening_value' => '0',
            'active' => 'Yes',
        ],
    ]);

    $preview = $this->manager->preview($path, InventoryBulkImportType::RawMaterial);
    $result = $this->manager->import($path, InventoryBulkImportType::RawMaterial, $this->director);

    expect($preview->counts['duplicate'])->toBeGreaterThan(0)
        ->and($preview->counts['valid'])->toBe(2)
        ->and($preview->counts['invalid'])->toBe(2)
        ->and($result->imported)->toBe(2)
        ->and($result->failed)->toBe(2)
        ->and(RawMaterial::query()->where('material_name', 'Valid New RM')->exists())->toBeTrue()
        ->and(RawMaterial::query()->where('material_name', 'Dup In File')->exists())->toBeTrue();
});

it('isolates failed rows so valid rows still import', function (): void {
    $path = inventoryImportCsv(InventoryBulkImportType::RawMaterial, [
        [
            'material_name' => 'Good Row',
            'unit' => 'Kg',
            'opening_quantity' => '0',
            'opening_value' => '0',
            'active' => 'Yes',
        ],
        [
            'material_name' => 'Bad Unit Row',
            'unit' => 'Furlong',
            'opening_quantity' => '0',
            'opening_value' => '0',
            'active' => 'Yes',
        ],
        [
            'material_name' => '',
            'unit' => 'Kg',
            'opening_quantity' => '0',
            'opening_value' => '0',
            'active' => 'Yes',
        ],
    ]);

    $result = $this->manager->import($path, InventoryBulkImportType::RawMaterial, $this->director);

    expect($result->imported)->toBe(1)
        ->and($result->failed)->toBe(2)
        ->and(RawMaterial::query()->where('material_name', 'Good Row')->exists())->toBeTrue();
});

it('builds a failed-rows excel error report', function (): void {
    $path = inventoryImportCsv(InventoryBulkImportType::RawMaterial, [[
        'material_name' => 'Bad Qty',
        'unit' => 'Kg',
        'opening_quantity' => '-5',
        'opening_value' => '10',
        'active' => 'Yes',
    ]]);

    $result = $this->manager->import($path, InventoryBulkImportType::RawMaterial, $this->director);
    $export = new InventoryBulkImportErrorReportExport(InventoryBulkImportType::RawMaterial, $result->errors);

    expect($result->failed)->toBe(1)
        ->and($export->headings())->toContain('Error Reason')
        ->and($export->array()[0])->toContain('Opening Quantity cannot be negative.');
});

it('chunk-imports a larger raw material file without aborting on one failure', function (): void {
    $rows = [];
    for ($i = 1; $i <= 120; $i++) {
        $rows[] = [
            'material_name' => "Chunk RM {$i}",
            'unit' => 'Kg',
            'opening_quantity' => $i === 50 ? '-1' : '0',
            'opening_value' => '0',
            'active' => 'Yes',
        ];
    }

    $path = inventoryImportCsv(InventoryBulkImportType::RawMaterial, $rows);
    $result = $this->manager->import($path, InventoryBulkImportType::RawMaterial, $this->director);

    expect($result->totalRows)->toBe(120)
        ->and($result->imported)->toBe(119)
        ->and($result->failed)->toBe(1)
        ->and(RawMaterial::query()->count())->toBe(119);
});

it('exports combined master code mapping', function (): void {
    app(RawMaterialCreateService::class)->create(
        materialData: [
            'material_name' => 'Map RM',
            'unit' => 'Kg',
            'minimum_stock' => 0,
            'status' => true,
        ],
        opening: ['quantity' => 0, 'value' => 0],
        user: $this->director,
    );

    $export = new InventoryCodeMappingExport(InventoryBulkImportType::RawMaterial, combined: true);

    expect($export->headings())->toBe(['Module', 'Code', 'Name', 'Unit', 'Active'])
        ->and($export->array())->not->toBeEmpty()
        ->and($export->array()[0][0])->toBe('Raw Material');
});

it('exports finished product code mapping from finished_products joined to products', function (): void {
    $sales = Product::query()->create([
        'product_name' => 'Mapping Sales FG',
        'category' => 'General',
        'uom' => 'Nos',
        'status' => true,
        'manufacturing_enabled' => false,
        'current_finished_stock' => 0,
    ]);

    $product = app(FinishedProductCreateService::class)->create(
        productData: [
            'linked_product_id' => $sales->id,
            'product_name' => 'Mapping Sales FG',
            'unit' => 'Nos',
            'minimum_finished_stock' => 1,
            'status' => true,
        ],
        opening: [
            'quantity' => 12,
            'value' => 240,
            'date' => now('Asia/Kolkata')->toDateString(),
        ],
        user: $this->director,
    );
    $product->load('finishedProduct');

    $export = new InventoryCodeMappingExport(InventoryBulkImportType::FinishedProduct);

    expect($export->headings())->toBe([
        'Finished Product Code',
        'Existing Product Code',
        'Existing Product Name',
        'Unit',
        'Current Stock',
    ]);

    $rows = $export->array();
    $match = collect($rows)->first(
        fn (array $row): bool => ($row[0] ?? '') === $product->finishedProduct->finished_product_code
    );

    expect($match)->not->toBeNull()
        ->and($match[1])->toBe($product->product_code)
        ->and($match[2])->toBe('Mapping Sales FG')
        ->and($match[3])->toBe('Nos')
        ->and($match[4])->toBe('12.000');
});
