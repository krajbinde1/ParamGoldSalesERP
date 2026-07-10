<?php

namespace App\Filament\Resources\DealerVisits\Pages;

use App\Filament\Resources\DealerVisits\DealerVisitResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditDealerVisit extends EditRecord
{
    protected static string $resource = DealerVisitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
