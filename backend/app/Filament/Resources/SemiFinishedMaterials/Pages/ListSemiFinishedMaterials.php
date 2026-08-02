<?php

namespace App\Filament\Resources\SemiFinishedMaterials\Pages;

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
                ->authorize(fn (): bool => SemiFinishedMaterialResource::canCreate()),
        ];
    }
}
