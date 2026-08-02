<?php

namespace App\Filament\Resources\ProductionBatches\Pages;

use App\Filament\Resources\ProductionBatches\ProductionBatchResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListProductionBatches extends ListRecords
{
    protected static string $resource = ProductionBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newProductionEntry')
                ->label('New Production Entry')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->url(fn (): string => ProductionBatchResource::getUrl('production-entry'))
                ->visible(fn (): bool => ProductionBatchResource::canPostProduction()),
        ];
    }
}
