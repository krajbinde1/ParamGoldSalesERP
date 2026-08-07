<?php

namespace App\Filament\Resources\FinishedProducts\Pages;

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
        ];
    }
}
