<?php

namespace App\Filament\Resources\DealerVisits\Pages;

use App\Filament\Resources\DealerVisits\DealerVisitResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDealerVisit extends ViewRecord
{
    protected static string $resource = DealerVisitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
