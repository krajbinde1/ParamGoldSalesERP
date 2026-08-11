<?php

namespace App\Filament\Resources\PackagingMaterials\Pages;

use App\Enums\InventoryBulkImportType;
use App\Filament\Actions\Inventory\DownloadInventoryImportTemplateAction;
use App\Filament\Actions\Inventory\ImportInventoryMaterialsAction;
use App\Filament\Resources\PackagingMaterials\PackagingMaterialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPackagingMaterials extends ListRecords
{
    protected static string $resource = PackagingMaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add Packaging Material')
                ->icon('heroicon-o-plus')
                ->authorize(fn (): bool => PackagingMaterialResource::canCreate()),
            DownloadInventoryImportTemplateAction::make(
                type: InventoryBulkImportType::PackagingMaterial,
            ),
            ImportInventoryMaterialsAction::make(
                type: InventoryBulkImportType::PackagingMaterial,
                modalHeading: 'Import Packaging Material',
            ),
        ];
    }
}
