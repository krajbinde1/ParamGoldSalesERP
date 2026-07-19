<?php

namespace App\Filament\Resources\Targets\Pages;

use App\Filament\Resources\Targets\WeeklyTargetResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditWeeklyTarget extends EditRecord
{
    protected static string $resource = WeeklyTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
