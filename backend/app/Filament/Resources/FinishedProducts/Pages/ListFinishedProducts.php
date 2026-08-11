<?php

namespace App\Filament\Resources\FinishedProducts\Pages;

use App\Enums\InventoryBulkImportType;
use App\Filament\Actions\Inventory\DownloadInventoryImportTemplateAction;
use App\Filament\Actions\Inventory\ImportInventoryMaterialsAction;
use App\Filament\Resources\FinishedProducts\FinishedProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFinishedProducts extends ListRecords
{
    protected static string $resource = FinishedProductResource::class;

    protected static ?string $title = 'Finished Goods Inventory';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Set Opening Stock')
                ->authorize(fn (): bool => FinishedProductResource::canCreate()),
            DownloadInventoryImportTemplateAction::make(
                type: InventoryBulkImportType::FinishedGoodsOpeningStock,
                label: 'Download Opening Stock Template',
                name: 'downloadFgOpeningStockTemplate',
            ),
            ImportInventoryMaterialsAction::make(
                type: InventoryBulkImportType::FinishedGoodsOpeningStock,
                label: 'Import Opening Stock',
                name: 'importFgOpeningStock',
                modalHeading: 'Import Finished Goods Opening Stock',
                modalDescription: 'Upload the Opening Stock template. Matching uses Product Code from Sales Products. This updates opening stock only — it does not create products.',
            ),
        ];
    }
}
