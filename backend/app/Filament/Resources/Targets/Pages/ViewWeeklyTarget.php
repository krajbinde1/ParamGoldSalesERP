<?php

namespace App\Filament\Resources\Targets\Pages;

use App\Filament\Resources\Targets\WeeklyTargetResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewWeeklyTarget extends ViewRecord
{
    protected static string $resource = WeeklyTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
