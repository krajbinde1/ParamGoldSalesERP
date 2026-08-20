<?php

namespace App\Filament\Resources\Farmers\Pages;

use App\Filament\Resources\Farmers\FarmerResource;
use App\Filament\Resources\Farmers\Widgets\FarmerStats;
use Filament\Resources\Pages\ListRecords;

class ListFarmers extends ListRecords
{
    protected static string $resource = FarmerResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            FarmerStats::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return [
            'default' => 1,
            'md' => 2,
            'xl' => 5,
        ];
    }
}
