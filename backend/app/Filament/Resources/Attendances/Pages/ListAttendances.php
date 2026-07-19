<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Attendances\Widgets\MonthlyAttendanceSummary;
use App\Models\Attendance;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAttendances extends ListRecords
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MonthlyAttendanceSummary::class,
        ];
    }

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->whereDate('attendance_date', Attendance::businessToday()->toDateString());
    }
}
