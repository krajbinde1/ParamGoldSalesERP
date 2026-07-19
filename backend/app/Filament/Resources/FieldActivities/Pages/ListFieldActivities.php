<?php

namespace App\Filament\Resources\FieldActivities\Pages;

use App\Filament\Resources\FieldActivities\FieldActivityResource;
use Filament\Resources\Pages\ListRecords;

class ListFieldActivities extends ListRecords
{
    protected static string $resource = FieldActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
