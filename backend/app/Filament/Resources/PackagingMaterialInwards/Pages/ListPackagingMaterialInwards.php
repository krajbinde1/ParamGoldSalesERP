<?php

namespace App\Filament\Resources\PackagingMaterialInwards\Pages;

use App\Filament\Resources\PackagingMaterialInwards\PackagingMaterialInwardResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPackagingMaterialInwards extends ListRecords
{
    protected static string $resource = PackagingMaterialInwardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Packaging Material Inward'),
        ];
    }
}
