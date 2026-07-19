<?php

namespace App\Filament\Resources\DealerVisits\Pages;

use App\Filament\Resources\DealerVisits\DealerVisitResource;
use Filament\Resources\Pages\ListRecords;

class ListDealerVisits extends ListRecords
{
    protected static string $resource = DealerVisitResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
