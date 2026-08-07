<?php

use App\Enums\InventoryBulkImportType;
use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Exports\Inventory\FinishedGoodsOpeningStockTemplateExport;
use App\Models\FinishedProduct;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\RawMaterialInward;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\BulkImport\InventoryBulkImportManager;
use App\Services\Inventory\BulkImport\InventoryBulkImportTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Protection;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->director = User::query()->create([
        'name' => 'FG Opening Import Director',
        'email' => 'fg.opening.import.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);

    $this->manager = app(InventoryBulkImportManager::class);
});

function fgOpeningStockImportCsv(array $dataRows): string
{
    $type = InventoryBulkImportType::FinishedGoodsOpeningStock;
    $columns = InventoryBulkImportTemplate::allColumns($type);
    $lines = [implode(',', $columns)];

    foreach ($dataRows as $row) {
        $values = [];
        foreach ($columns as $column) {
            $values[] = (string) ($row[$column] ?? '');
        }
        $lines[] = implode(',', $values);
    }

    $path = storage_path('framework/testing/fg-opening-import-'.uniqid().'.csv');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, implode("\n", $lines));

    return $path;
}

function createSalesProduct(string $name = 'Sales Product'): Product
{
    return Product::query()->create([
        'product_name' => $name,
        'category' => 'General',
        'uom' => 'Nos',
        'nos_per_case' => 1,
        'gst_percentage' => 0,
        'mrp' => 0,
        'distributor_price' => 0,
        'dealer_price' => 0,
        'retail_price' => 0,
        'status' => true,
        'manufacturing_enabled' => false,
        'current_finished_stock' => 0,
        'opening_finished_stock' => 0,
    ]);
}

it('downloads a template from Sales Operations products with codes and names', function (): void {
    $product = createSalesProduct('Template Sales Product');

    $export = new FinishedGoodsOpeningStockTemplateExport;
    $rows = $export->collection();

    expect($export->headings())->toBe([
        'Product Code',
        'Product Name',
        'Opening Stock Quantity',
        'Opening Stock Value',
        'Opening Stock Date',
    ]);

    $matched = $rows->first(
        fn (array $row): bool => ($row[0] ?? '') === $product->product_code
    );

    expect($matched)->not->toBeNull()
        ->and($matched[1])->toBe($product->product_name)
        ->and($matched[2])->toBe('')
        ->and($matched[3])->toBe('')
        ->and($matched[4])->toBe('');
});

it('includes every sales product as its own excel row matching Product model count', function (): void {
    $created = collect([
        createSalesProduct('Alpha Sales Pack'),
        createSalesProduct('Beta Sales Pack'),
        createSalesProduct('Charlie Sales Pack'),
    ]);

    expect(Product::query()->count())->toBe(3)
        ->and(FinishedProduct::query()->count())->toBe(0);

    $binary = Excel::raw(
        new FinishedGoodsOpeningStockTemplateExport,
        \Maatwebsite\Excel\Excel::XLSX,
    );

    $path = storage_path('framework/testing/fg-opening-all-rows-'.uniqid().'.xlsx');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $binary);

    $sheet = IOFactory::load($path)->getActiveSheet();
    $highestRow = (int) $sheet->getHighestDataRow();

    expect($highestRow)->toBe(Product::query()->count() + 1)
        ->and($sheet->getCell('A1')->getValue())->toBe('Product Code')
        ->and($sheet->getCell('B1')->getValue())->toBe('Product Name');

    $codesInSheet = [];
    for ($row = 2; $row <= $highestRow; $row++) {
        $codesInSheet[] = (string) $sheet->getCell('A'.$row)->getValue();
        expect((string) $sheet->getCell('C'.$row)->getValue())->toBe('')
            ->and((string) $sheet->getCell('D'.$row)->getValue())->toBe('')
            ->and((string) $sheet->getCell('E'.$row)->getValue())->toBe('');
    }

    foreach ($created as $product) {
        expect($codesInSheet)->toContain($product->product_code);
    }

    @unlink($path);
});

it('locks product code/name and formats editable opening stock columns in the excel template', function (): void {
    createSalesProduct('Locked Template Product');

    $binary = Excel::raw(
        new FinishedGoodsOpeningStockTemplateExport,
        \Maatwebsite\Excel\Excel::XLSX,
    );

    $path = storage_path('framework/testing/fg-opening-template-'.uniqid().'.xlsx');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $binary);

    $spreadsheet = IOFactory::load($path);
    $sheet = $spreadsheet->getActiveSheet();

    expect($sheet->getProtection()->getSheet())->toBeTrue()
        ->and($sheet->getStyle('A2')->getProtection()->getLocked())->toBe(Protection::PROTECTION_PROTECTED)
        ->and($sheet->getStyle('B2')->getProtection()->getLocked())->toBe(Protection::PROTECTION_PROTECTED)
        ->and($sheet->getStyle('C2')->getProtection()->getLocked())->toBe(Protection::PROTECTION_UNPROTECTED)
        ->and($sheet->getStyle('D2')->getProtection()->getLocked())->toBe(Protection::PROTECTION_UNPROTECTED)
        ->and($sheet->getStyle('E2')->getProtection()->getLocked())->toBe(Protection::PROTECTION_UNPROTECTED)
        ->and($sheet->getStyle('E2')->getNumberFormat()->getFormatCode())->toBe('DD-MM-YYYY');

    $qtyValidation = $sheet->getCell('C2')->getDataValidation();
    $valueValidation = $sheet->getCell('D2')->getDataValidation();
    $dateValidation = $sheet->getCell('E2')->getDataValidation();

    expect($qtyValidation->getType())->toBe(DataValidation::TYPE_DECIMAL)
        ->and($valueValidation->getType())->toBe(DataValidation::TYPE_DECIMAL)
        ->and($dateValidation->getType())->toBe(DataValidation::TYPE_DATE);

    @unlink($path);
});

it('imports opening stock for an existing sales product without creating products or production', function (): void {
    $product = createSalesProduct('Import Target Product');
    $beforeProductCount = Product::query()->count();
    $beforeFpCount = FinishedProduct::query()->count();
    $beforeProduction = ProductionBatch::query()->count();
    $beforeInward = RawMaterialInward::query()->count();

    $path = fgOpeningStockImportCsv([
        [
            'product_code' => $product->product_code,
            'product_name' => $product->product_name,
            'opening_quantity' => '100',
            'opening_value' => '50000',
            'opening_date' => now('Asia/Kolkata')->toDateString(),
        ],
        [
            'product_code' => 'PRDIGNORE',
            'product_name' => 'Ignored',
            'opening_quantity' => '',
            'opening_value' => '',
            'opening_date' => '',
        ],
    ]);

    $preview = $this->manager->preview($path, InventoryBulkImportType::FinishedGoodsOpeningStock);
    expect($preview->counts['valid'])->toBe(1)
        ->and($preview->counts['ignored'])->toBe(1)
        ->and($preview->counts['to_import'])->toBe(1);

    $result = $this->manager->import($path, InventoryBulkImportType::FinishedGoodsOpeningStock, $this->director);
    $product->refresh();

    $ledger = StockLedger::query()
        ->where('product_id', $product->id)
        ->where('item_type', StockItemType::FinishedProduct)
        ->where('transaction_type', StockTransactionType::OpeningStock)
        ->first();

    expect($result->imported)->toBe(1)
        ->and(Product::query()->count())->toBe($beforeProductCount)
        ->and(FinishedProduct::query()->count())->toBe($beforeFpCount)
        ->and(ProductionBatch::query()->count())->toBe($beforeProduction)
        ->and(RawMaterialInward::query()->count())->toBe($beforeInward)
        ->and((float) $product->current_finished_stock)->toBe(100.0)
        ->and((float) $product->opening_finished_stock)->toBe(100.0)
        ->and((float) $product->weighted_average_cost)->toBe(500.0)
        ->and($ledger)->not->toBeNull()
        ->and((float) $ledger->quantity_in)->toBe(100.0)
        ->and((float) $ledger->transaction_value)->toBe(50000.0)
        ->and($ledger->remarks)->toBe('Opening Stock')
        ->and($result->mappings[0]['product_code'])->toBe($product->product_code)
        ->and($result->mappings[0]['opening_rate'])->toBe('500.0000')
        ->and($result->mappings[0]['status'])->toBe('Success');
});

it('rejects opening stock import when opening stock already exists', function (): void {
    $product = createSalesProduct('Already Opened Product');
    app(\App\Services\Inventory\FinishedProductCreateService::class)
        ->applyOpeningStockToExisting(
            product: $product,
            opening: [
                'quantity' => 10,
                'value' => 1000,
                'date' => now('Asia/Kolkata')->toDateString(),
                'remarks' => 'Opening Stock',
            ],
            user: $this->director,
        );

    $path = fgOpeningStockImportCsv([[
        'product_code' => $product->product_code,
        'product_name' => $product->product_name,
        'opening_quantity' => '5',
        'opening_value' => '500',
        'opening_date' => now('Asia/Kolkata')->toDateString(),
    ]]);

    $preview = $this->manager->preview($path, InventoryBulkImportType::FinishedGoodsOpeningStock);

    expect($preview->counts['invalid'])->toBe(1)
        ->and($preview->rows[0]['error'])->toBe('Opening stock already exists for this Finished Product.');
});

it('rejects unknown sales product codes and name mismatches', function (): void {
    $product = createSalesProduct('Correct Name Product');

    $unknownPath = fgOpeningStockImportCsv([[
        'product_code' => 'PRD999999',
        'product_name' => 'Anything',
        'opening_quantity' => '1',
        'opening_value' => '10',
        'opening_date' => now('Asia/Kolkata')->toDateString(),
    ]]);

    $unknownPreview = $this->manager->preview($unknownPath, InventoryBulkImportType::FinishedGoodsOpeningStock);
    expect($unknownPreview->rows[0]['error'])->toBe('Product Code does not match an existing Sales Product.');

    $mismatchPath = fgOpeningStockImportCsv([[
        'product_code' => $product->product_code,
        'product_name' => 'Wrong Name',
        'opening_quantity' => '1',
        'opening_value' => '10',
        'opening_date' => now('Asia/Kolkata')->toDateString(),
    ]]);

    $mismatchPreview = $this->manager->preview($mismatchPath, InventoryBulkImportType::FinishedGoodsOpeningStock);
    expect($mismatchPreview->rows[0]['error'])->toBe('Product Name does not match Product Code.');
});

it('reads the Product Code / Product Name template headers used on download', function (): void {
    $product = createSalesProduct('Header Import Product');

    $lines = [
        'Product Code,Product Name,Opening Stock Quantity,Opening Stock Value,Opening Stock Date',
        $product->product_code.','.$product->product_name.',25,12500,'.now('Asia/Kolkata')->toDateString(),
    ];
    $path = storage_path('framework/testing/fg-opening-headers-'.uniqid().'.csv');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, implode("\n", $lines));

    $result = $this->manager->import($path, InventoryBulkImportType::FinishedGoodsOpeningStock, $this->director);
    $product->refresh();

    expect($result->imported)->toBe(1)
        ->and((float) $product->current_finished_stock)->toBe(25.0)
        ->and((float) $product->weighted_average_cost)->toBe(500.0)
        ->and(Product::query()->count())->toBe(1)
        ->and(FinishedProduct::query()->count())->toBe(0);
});
