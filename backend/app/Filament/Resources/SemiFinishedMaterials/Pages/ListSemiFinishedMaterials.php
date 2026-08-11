<?php

namespace App\Filament\Resources\SemiFinishedMaterials\Pages;

use App\Enums\InventoryBulkImportType;
use App\Filament\Actions\Inventory\DownloadInventoryImportTemplateAction;
use App\Filament\Actions\Inventory\ImportInventoryMaterialsAction;
use App\Filament\Resources\SemiFinishedMaterials\SemiFinishedMaterialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSemiFinishedMaterials extends ListRecords
{
    protected static string $resource = SemiFinishedMaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add Semi-Finished Material')
                ->icon('heroicon-o-plus')
                ->authorize(fn (): bool => SemiFinishedMaterialResource::canCreate()),
            DownloadInventoryImportTemplateAction::make(
                type: InventoryBulkImportType::SemiFinished,
            ),
            ImportInventoryMaterialsAction::make(
                type: InventoryBulkImportType::SemiFinished,
                modalHeading: 'Import Semi-Finished Material',
            ),
        ];
    }
}
