<?php

namespace App\Filament\Resources\RawMaterialInwards\Pages;

use App\Filament\Resources\RawMaterialInwards\RawMaterialInwardResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRawMaterialInwards extends ListRecords
{
    protected static string $resource = RawMaterialInwardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Raw Material Inward'),
        ];
    }
}
