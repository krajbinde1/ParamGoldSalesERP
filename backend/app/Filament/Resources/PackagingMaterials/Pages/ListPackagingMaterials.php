<?php

namespace App\Filament\Resources\PackagingMaterials\Pages;

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
                ->label('New Packaging Material')
                ->authorize(fn (): bool => PackagingMaterialResource::canCreate()),
        ];
    }
}
