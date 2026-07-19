<?php

namespace App\Filament\Resources\Targets\Pages;

use App\Filament\Resources\Targets\WeeklyTargetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWeeklyTarget extends CreateRecord
{
    protected static string $resource = WeeklyTargetResource::class;
}
