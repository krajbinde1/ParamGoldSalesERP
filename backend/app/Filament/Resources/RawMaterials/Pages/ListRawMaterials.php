<?php

namespace App\Filament\Resources\RawMaterials\Pages;

use App\Enums\InventoryBulkImportType;
use App\Filament\Actions\Inventory\DownloadInventoryImportTemplateAction;
use App\Filament\Actions\Inventory\ImportInventoryMaterialsAction;
use App\Filament\Resources\RawMaterials\RawMaterialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRawMaterials extends ListRecords
{
    protected static string $resource = RawMaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add Raw Material')
                ->icon('heroicon-o-plus')
                ->authorize(fn (): bool => RawMaterialResource::canCreate()),
            DownloadInventoryImportTemplateAction::make(
                type: InventoryBulkImportType::RawMaterial,
            ),
            ImportInventoryMaterialsAction::make(
                type: InventoryBulkImportType::RawMaterial,
                modalHeading: 'Import Raw Material',
            ),
        ];
    }
}
