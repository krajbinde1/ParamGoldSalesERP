<?php

use App\Exports\ProductImportErrorReportExport;
use App\Exports\ProductImportTemplateExport;
use App\Models\Product;
use App\Services\Products\ProductBulkImportService;
use App\Services\Products\ProductBulkImportTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function productImportHeaderLine(): string
{
    return implode(',', ProductBulkImportTemplate::allColumns());
}

function productImportDataLine(array $overrides = []): string
{
    $values = [
        'product_name' => 'Sample Fertilizer',
        'product_code' => '',
        'dealer_price' => '450.00',
        'nos_per_case' => '1',
        'gst_percentage' => '12',
        'status' => 'Active',
    ];

    $values = array_merge($values, $overrides);

    return implode(',', array_map(
        fn (string $column): string => $values[$column],
        ProductBulkImportTemplate::allColumns(),
    ));
}

it('generates a product import template with mandatory and optional markers', function () {
    $export = new ProductImportTemplateExport;
    $rows = $export->array();

    expect($rows[0][1])->toBe('MANDATORY')
        ->and($rows[0][2])->toBe('OPTIONAL')
        ->and($rows[1])->toContain('Product Name *')
        ->and($rows[1])->toContain('Product Code')
        ->and($rows[1])->toContain('Dealer Price *')
        ->and($rows[1])->toContain('Nos Per Case *')
        ->and($rows[1])->toContain('GST % *')
        ->and($rows[1])->toContain('Status *');
});

it('imports valid product rows from csv', function () {
    $csv = implode("\n", [
        productImportHeaderLine(),
        productImportDataLine(),
        productImportDataLine([
            'product_name' => 'Organic Mix',
            'dealer_price' => '799.50',
        ]),
    ]);

    $path = storage_path('framework/testing/product-import-valid.csv');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $csv);

    $result = app(ProductBulkImportService::class)->import($path);

    expect($result->created)->toBe(2)
        ->and($result->updated)->toBe(0)
        ->and($result->failed())->toBe(0)
        ->and(Product::query()->count())->toBe(2)
        ->and(Product::query()->where('product_name', 'Sample Fertilizer')->value('product_code'))->toStartWith('PRD');
});

it('skips invalid rows and imports valid rows', function () {
    $csv = implode("\n", [
        productImportHeaderLine(),
        productImportDataLine(),
        productImportDataLine(['product_name' => '', 'dealer_price' => '100']),
        productImportDataLine([
            'product_name' => 'Invalid GST Product',
            'gst_percentage' => '99',
        ]),
    ]);

    $path = storage_path('framework/testing/product-import-invalid.csv');
    File::put($path, $csv);

    $result = app(ProductBulkImportService::class)->import($path);

    expect($result->created)->toBe(1)
        ->and($result->failed())->toBe(2)
        ->and($result->errors[0]->reason)->toContain('Missing mandatory field: product_name')
        ->and(Product::query()->count())->toBe(1);
});

it('auto-generates product code when product code is blank', function () {
    $path = storage_path('framework/testing/product-import-autocode.csv');
    File::put($path, productImportHeaderLine()."\n".productImportDataLine());

    app(ProductBulkImportService::class)->import($path);

    expect(Product::query()->value('product_code'))->toBe('PRD000001');
});

it('updates an existing product when product code already exists', function () {
    Product::query()->create([
        'product_code' => 'PRD000010',
        'product_name' => 'Old Name',
        'category' => 'General',
        'uom' => 'Bag',
        'gst_percentage' => 5,
        'dealer_price' => 100,
        'status' => true,
    ]);

    $csv = productImportHeaderLine()."\n".productImportDataLine([
        'product_code' => 'PRD000010',
        'product_name' => 'Updated Name',
        'dealer_price' => '550',
        'gst_percentage' => '18',
    ]);

    $path = storage_path('framework/testing/product-import-update.csv');
    File::put($path, $csv);

    $result = app(ProductBulkImportService::class)->import($path);

    expect($result->created)->toBe(0)
        ->and($result->updated)->toBe(1)
        ->and(Product::query()->count())->toBe(1)
        ->and(Product::query()->first()->product_name)->toBe('Updated Name')
        ->and((float) Product::query()->value('dealer_price'))->toBe(550.0)
        ->and((string) Product::query()->value('gst_percentage'))->toBe('18.00');
});

it('does not create duplicate products for repeated existing product codes', function () {
    Product::query()->create([
        'product_code' => 'PRD000020',
        'product_name' => 'Existing Product',
        'category' => 'General',
        'uom' => 'Bag',
        'gst_percentage' => 12,
        'dealer_price' => 300,
        'status' => true,
    ]);

    $csv = productImportHeaderLine()."\n".implode("\n", [
        productImportDataLine([
            'product_code' => 'PRD000020',
            'product_name' => 'First Update',
            'dealer_price' => '310',
        ]),
        productImportDataLine([
            'product_code' => 'PRD000020',
            'product_name' => 'Second Update',
            'dealer_price' => '320',
        ]),
    ]);

    $path = storage_path('framework/testing/product-import-duplicate.csv');
    File::put($path, $csv);

    $result = app(ProductBulkImportService::class)->import($path);

    expect($result->created)->toBe(0)
        ->and($result->updated)->toBe(2)
        ->and(Product::query()->count())->toBe(1)
        ->and(Product::query()->value('product_name'))->toBe('Second Update');
});

it('builds an excel error report for failed rows', function () {
    $csv = implode("\n", [
        productImportHeaderLine(),
        productImportDataLine(['gst_percentage' => '99']),
    ]);
    $path = storage_path('framework/testing/product-import-error-report.csv');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $csv);

    $result = app(ProductBulkImportService::class)->import($path);
    $export = new ProductImportErrorReportExport($result->errors);

    expect($result->failed())->toBe(1)
        ->and($export->headings())->toContain('Error')
        ->and($export->array()[0][7])->toContain('GST % must be one of');
});

it('imports files exported from the marked template format', function () {
    $templateRows = (new ProductImportTemplateExport)->array();
    $handle = fopen('php://temp', 'r+');

    foreach ($templateRows as $row) {
        fputcsv($handle, $row);
    }

    fputcsv($handle, explode(',', productImportDataLine([
        'product_name' => 'Template Product',
    ])));

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    $path = storage_path('framework/testing/product-import-template.csv');
    File::put($path, $csv);

    $result = app(ProductBulkImportService::class)->import($path);

    expect($result->created)->toBe(1)
        ->and(Product::query()->where('product_name', 'Template Product')->exists())->toBeTrue();
});

it('previews uploaded rows before import', function () {
    $csv = productImportHeaderLine()."\n".implode("\n", [
        productImportDataLine(),
        productImportDataLine(['product_name' => '', 'gst_percentage' => '12']),
    ]);

    $path = storage_path('framework/testing/product-import-preview.csv');
    File::put($path, $csv);

    $preview = app(ProductBulkImportService::class)->preview($path);

    expect($preview)->toHaveCount(2)
        ->and($preview[0]['is_valid'])->toBeTrue()
        ->and($preview[1]['is_valid'])->toBeFalse();
});
