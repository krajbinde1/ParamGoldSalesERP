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
 * Raw Material Import — uses RawMaterialBulkImporter → RawMaterialCreateService.
 * Material codes are never accepted from Excel; they are assigned on create.
 */
class RawMaterialImport extends Page implements HasForms
{
    use HandlesInventoryMaterialImport;
    use InteractsWithForms;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?string $navigationParentItem = 'Bulk Upload';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Raw Material Import';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    protected static ?string $slug = 'raw-material-import';

    protected static ?string $title = 'Raw Material Import';

    protected string $view = 'filament.pages.inventory-material-import';

    public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $this->configureImportUploadForm($schema);
    }

    protected static function importType(): InventoryBulkImportType
    {
        return InventoryBulkImportType::RawMaterial;
    }

    protected static function importHeading(): string
    {
        return 'Raw Material Import';
    }

    protected static function importDescription(): string
    {
        return 'Import raw materials with opening stock and opening stock value.';
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
        return 'Generated Material Code';
    }

    protected static function uploadFilePrefix(): string
    {
        return 'rm';
    }

    protected static function errorCachePrefix(): string
    {
        return 'raw-material-import-errors';
    }

    protected static function errorReportFilenamePrefix(): string
    {
        return 'raw-material-import-errors';
    }
}
