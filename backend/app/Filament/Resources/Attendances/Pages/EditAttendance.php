<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Filament\Concerns\RedirectsToPreviousPageAfterSave;
use App\Filament\Resources\Attendances\AttendanceResource;
use Filament\Resources\Pages\EditRecord;

class EditAttendance extends EditRecord
{
    use RedirectsToPreviousPageAfterSave;

    protected static string $resource = AttendanceResource::class;

    public static function canAccess(array $parameters = []): bool
    {
        return false;
    }
}
