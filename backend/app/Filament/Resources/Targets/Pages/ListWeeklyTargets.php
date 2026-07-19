<?php

namespace App\Filament\Resources\Targets\Pages;

use App\Filament\Resources\Targets\WeeklyTargetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWeeklyTargets extends ListRecords
{
    protected static string $resource = WeeklyTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
