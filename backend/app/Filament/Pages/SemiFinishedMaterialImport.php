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
 * Semi-Finished Material Import — uses SemiFinishedMaterialBulkImporter → SemiFinishedMaterialCreateService.
 * Codes are never accepted from Excel; they are assigned on create.
 */
class SemiFinishedMaterialImport extends Page implements HasForms
{
    use HandlesInventoryMaterialImport;
    use InteractsWithForms;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?string $navigationParentItem = 'Bulk Upload';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Semi-Finished Material Import';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $slug = 'semi-finished-material-import';

    protected static ?string $title = 'Semi-Finished Material Import';

    protected string $view = 'filament.pages.inventory-material-import';

    public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $this->configureImportUploadForm($schema);
    }

    protected static function importType(): InventoryBulkImportType
    {
        return InventoryBulkImportType::SemiFinished;
    }

    protected static function importHeading(): string
    {
        return 'Semi-Finished Material Import';
    }

    protected static function importDescription(): string
    {
        return 'Import semi-finished materials with opening stock and opening stock value.';
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
        return 'Generated Semi-Finished Material Code';
    }

    protected static function uploadFilePrefix(): string
    {
        return 'sfm';
    }

    protected static function errorCachePrefix(): string
    {
        return 'semi-finished-material-import-errors';
    }

    protected static function errorReportFilenamePrefix(): string
    {
        return 'semi-finished-material-import-errors';
    }
}
