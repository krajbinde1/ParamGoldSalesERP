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
 * Packaging Material Import — uses PackagingMaterialBulkImporter → PackagingMaterialCreateService.
 * Packaging codes are never accepted from Excel; they are assigned on create.
 */
class PackagingMaterialImport extends Page implements HasForms
{
    use HandlesInventoryMaterialImport;
    use InteractsWithForms;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?string $navigationParentItem = 'Bulk Upload';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Packaging Material Import';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $slug = 'packaging-material-import';

    protected static ?string $title = 'Packaging Material Import';

    protected string $view = 'filament.pages.inventory-material-import';

    public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $this->configureImportUploadForm($schema);
    }

    protected static function importType(): InventoryBulkImportType
    {
        return InventoryBulkImportType::PackagingMaterial;
    }

    protected static function importHeading(): string
    {
        return 'Packaging Material Import';
    }

    protected static function importDescription(): string
    {
        return 'Import packaging materials with opening stock and opening stock value.';
    }

    protected static function previewNameField(): string
    {
        return 'material_name';
    }

    protected static function resultNameLabel(): string
    {
        return 'Material Name';
    }

    protected static function resultCodeLabel(): string
    {
        return 'Generated Packaging Material Code';
    }

    protected static function uploadFilePrefix(): string
    {
        return 'pk';
    }

    protected static function errorCachePrefix(): string
    {
        return 'packaging-material-import-errors';
    }

    protected static function errorReportFilenamePrefix(): string
    {
        return 'packaging-material-import-errors';
    }
}
