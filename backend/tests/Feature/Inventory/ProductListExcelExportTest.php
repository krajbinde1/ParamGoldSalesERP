<?php

use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\UserRole;
use App\Exports\ProductListExport;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Bom;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

function productListExcelDirector(): User
{
    return User::query()->create([
        'name' => 'Product Excel List Director',
        'email' => 'product.excel.list.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
}

function productListExcelProduct(string $name, array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'product_name' => $name,
        'uom' => 'Nos',
        'nos_per_case' => 10,
        'gst_percentage' => 12,
        'dealer_price' => 100,
        'status' => true,
    ], $overrides));
}

function productListExcelAttachActiveBom(Product $product): Bom
{
    return Bom::query()->create([
        'output_type' => BomOutputType::FinishedProduct,
        'product_id' => $product->id,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);
}

it('shows the download excel action on the product list for an authorized user', function () {
    $admin = productListExcelDirector();

    Livewire::actingAs($admin)
        ->test(ListProducts::class)
        ->assertSuccessful()
        ->assertSee('Download Excel')
        ->assertSee('Download Current Filtered List')
        ->assertSee('Download All Products')
        ->assertActionVisible('downloadFilteredProducts')
        ->assertActionVisible('downloadAllProducts');
});

it('exports the current filtered product list including records beyond the first page', function () {
    Excel::fake();

    $admin = productListExcelDirector();
    $token = 'ExcelFilter '.uniqid();
    $matching = [];

    for ($i = 1; $i <= 12; $i++) {
        $matching[] = productListExcelProduct($token.' Active '.$i);
    }

    $inactiveMatch = productListExcelProduct($token.' Inactive', ['status' => false]);
    $otherProduct = productListExcelProduct('Other Product '.uniqid());

    $page = Livewire::actingAs($admin)
        ->test(ListProducts::class)
        ->set('tableSearch', $token)
        ->filterTable('status', true)
        ->assertSuccessful();

    expect($page->instance()->getTableRecords())->toHaveCount(10)
        ->and($page->instance()->getFilteredTableQuery()->count())->toBe(12)
        ->and($page->instance()->getFilteredTableQuery()->pluck('id')->all())
        ->not->toContain($inactiveMatch->id)
        ->not->toContain($otherProduct->id);

    $page->callAction('downloadFilteredProducts')
        ->assertHasNoErrors();

    $matchingCodes = collect($matching)->pluck('product_code')->all();

    Excel::assertDownloaded(
        'products-filtered-'.now()->format('Y-m-d').'.xlsx',
        function (ProductListExport $export) use ($matchingCodes, $inactiveMatch, $otherProduct): bool {
            $rows = $export->array();
            $codes = collect($rows)->pluck(0)->all();

            expect($rows)->toHaveCount(12)
                ->and($codes)->toEqualCanonicalizing($matchingCodes)
                ->and($codes)->not->toContain($inactiveMatch->product_code)
                ->and($codes)->not->toContain($otherProduct->product_code);

            return true;
        },
    );
});

it('exports all non-trashed products including inactive and bom not set', function () {
    Excel::fake();

    $admin = productListExcelDirector();
    $activeWithBom = productListExcelProduct('Excel All Active BOM '.uniqid());
    $activeWithoutBom = productListExcelProduct('Excel All Active No BOM '.uniqid());
    $inactiveWithBom = productListExcelProduct('Excel All Inactive BOM '.uniqid(), ['status' => false]);
    $inactiveWithoutBom = productListExcelProduct('Excel All Inactive No BOM '.uniqid(), ['status' => false]);
    $trashed = productListExcelProduct('Excel All Trashed '.uniqid());
    $trashed->delete();

    productListExcelAttachActiveBom($activeWithBom);
    productListExcelAttachActiveBom($inactiveWithBom);

    Livewire::actingAs($admin)
        ->test(ListProducts::class)
        ->filterTable('status', true)
        ->filterTable('bom_status', 'set')
        ->set('tableSearch', 'Active BOM')
        ->callAction('downloadAllProducts')
        ->assertHasNoErrors();

    Excel::assertDownloaded(
        'products-all-'.now()->format('Y-m-d').'.xlsx',
        function (ProductListExport $export) use (
            $activeWithBom,
            $activeWithoutBom,
            $inactiveWithBom,
            $inactiveWithoutBom,
            $trashed,
        ): bool {
            $rows = collect($export->array())->keyBy(0);

            expect($rows->has($activeWithBom->product_code))->toBeTrue()
                ->and($rows->has($activeWithoutBom->product_code))->toBeTrue()
                ->and($rows->has($inactiveWithBom->product_code))->toBeTrue()
                ->and($rows->has($inactiveWithoutBom->product_code))->toBeTrue()
                ->and($rows->has($trashed->product_code))->toBeFalse()
                ->and($rows[$activeWithBom->product_code][4])->toBe('BOM Set')
                ->and($rows[$activeWithoutBom->product_code][4])->toBe('BOM Not Set')
                ->and($rows[$inactiveWithBom->product_code][7])->toBe('Inactive')
                ->and($rows[$inactiveWithoutBom->product_code][4])->toBe('BOM Not Set')
                ->and($rows[$inactiveWithoutBom->product_code][7])->toBe('Inactive');

            return true;
        },
    );
});

it('exports the product list columns shown on the table', function () {
    $product = productListExcelProduct('Excel Columns '.uniqid(), [
        'uom' => 'Kg',
        'nos_per_case' => 24,
        'gst_percentage' => 18,
        'dealer_price' => 250.5,
        'status' => true,
    ]);
    productListExcelAttachActiveBom($product);

    $export = new ProductListExport(Product::query()->whereKey($product->id));

    expect($export->headings())->toBe([
        'Product Code',
        'Product Name',
        'UOM',
        'Nos/Case',
        'BOM Status',
        'GST',
        'Dealer Price',
        'Status',
    ])
        ->and($export->array())->toHaveCount(1)
        ->and($export->array()[0])->toBe([
            $product->product_code,
            $product->product_name,
            'Kg',
            24,
            'BOM Set',
            18.0,
            250.5,
            'Active',
        ]);
});
