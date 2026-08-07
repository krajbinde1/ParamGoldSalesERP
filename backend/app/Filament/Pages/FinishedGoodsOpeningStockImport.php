<?php

namespace App\Filament\Pages;

use App\Enums\InventoryBulkImportType;
use App\Exports\Inventory\FinishedGoodsOpeningStockTemplateExport;
use App\Filament\Pages\Concerns\HandlesInventoryMaterialImport;
use App\Models\Product;
use App\Services\Inventory\BulkImport\InventoryBulkImportTemplate;
use BackedEnum;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * Finished Goods Opening Stock Import.
 *
 * Template source: Sales Operations → Products (Product model).
 * Updates opening stock for existing sales products only.
 * Never creates products or product codes.
 */
class FinishedGoodsOpeningStockImport extends Page implements HasForms
{
    use HandlesInventoryMaterialImport;
    use InteractsWithForms;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?string $navigationParentItem = 'Bulk Upload';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'FG Opening Stock Import';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $slug = 'finished-goods-opening-stock-import';

    protected static ?string $title = 'Finished Goods Opening Stock Import';

    protected string $view = 'filament.pages.finished-goods-opening-stock-import';

    public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $this->configureImportUploadForm($schema);
    }

    protected static function importType(): InventoryBulkImportType
    {
        return InventoryBulkImportType::FinishedGoodsOpeningStock;
    }

    protected static function importHeading(): string
    {
        return 'Finished Goods Opening Stock Import';
    }

    protected static function importDescription(): string
    {
        return 'Download the template with Product Codes and Names from Sales Operations → Products. Fill Opening Stock Quantity, Value, and Date only. Matching uses Product Code — no products are created or deleted.';
    }

    protected static function previewNameField(): string
    {
        return 'product_name';
    }

    protected static function resultNameLabel(): string
    {
        return 'Product Name';
    }

    protected static function resultCodeLabel(): string
    {
        return 'Product Code';
    }

    protected static function uploadFilePrefix(): string
    {
        return 'fg-opening-stock';
    }

    protected static function errorCachePrefix(): string
    {
        return 'fg-opening-stock-import-errors';
    }

    protected static function errorReportFilenamePrefix(): string
    {
        return 'fg-opening-stock-import-errors';
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        $count = Product::query()->count();
        Log::info('Sales Products count: '.$count);

        return Excel::download(
            new FinishedGoodsOpeningStockTemplateExport,
            InventoryBulkImportTemplate::downloadFilename(static::importType()),
        );
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array{material_name:string,material_code:string,opening_quantity:float|int|string,opening_value:float|int|string,opening_rate:float|int|string,status:string}
     */
    protected function normalizeImportedMapping(array $mapping): array
    {
        return [
            'material_name' => (string) ($mapping['product_name'] ?? ''),
            'material_code' => (string) ($mapping['product_code'] ?? ''),
            'opening_quantity' => $mapping['opening_quantity'] ?? 0,
            'opening_value' => $mapping['opening_value'] ?? 0,
            'opening_rate' => $mapping['opening_rate'] ?? 0,
            'status' => (string) ($mapping['status'] ?? 'Success'),
        ];
    }
}
