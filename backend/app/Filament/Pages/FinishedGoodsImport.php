<?php

namespace App\Filament\Pages;

use App\Enums\InventoryBulkImportType;
use App\Filament\Pages\Concerns\HandlesInventoryMaterialImport;
use BackedEnum;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Finished Goods Import — links existing sales Products via FinishedProductCreateService.
 * Never creates duplicate Products. FP codes are assigned on create; not accepted from Excel.
 */
class FinishedGoodsImport extends Page implements HasForms
{
    use HandlesInventoryMaterialImport;
    use InteractsWithForms;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?string $navigationParentItem = 'Bulk Upload';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Finished Goods Import';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $slug = 'finished-goods-import';

    protected static ?string $title = 'Finished Goods Import';

    protected string $view = 'filament.pages.inventory-material-import';

    public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $this->configureImportUploadForm($schema);
    }

    protected static function importType(): InventoryBulkImportType
    {
        return InventoryBulkImportType::FinishedProduct;
    }

    protected static function importHeading(): string
    {
        return 'Finished Goods Import';
    }

    protected static function importDescription(): string
    {
        return 'Link existing sales products as finished goods with opening stock. Does not create duplicate products.';
    }

    protected static function previewNameField(): string
    {
        return 'existing_product';
    }

    protected static function resultNameLabel(): string
    {
        return 'Product Name';
    }

    protected static function resultCodeLabel(): string
    {
        return 'Generated Finished Product Code';
    }

    protected static function uploadFilePrefix(): string
    {
        return 'fg';
    }

    protected static function errorCachePrefix(): string
    {
        return 'finished-goods-import-errors';
    }

    protected static function errorReportFilenamePrefix(): string
    {
        return 'finished-goods-import-errors';
    }
}
