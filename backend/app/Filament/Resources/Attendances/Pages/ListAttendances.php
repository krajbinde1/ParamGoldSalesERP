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

    /**
     * Today's Attendance is the main table (top). Monthly summary sits below.
     */
    protected function getFooterWidgets(): array
    {
        return [
            MonthlyAttendanceSummary::class,
        ];
    }

    protected function getTableQuery(): Builder
    {
        $today = Attendance::businessToday()->toDateString();

        return parent::getTableQuery()
            ->where(function (Builder $query) use ($today): void {
                $query->whereDate('attendance_date', $today)
                    ->orWhere(function (Builder $open) use ($today): void {
                        $open->whereDate('attendance_date', '<', $today)
                            ->whereNotNull('punch_in_time')
                            ->whereNull('punch_out_time');
                    })
                    ->orWhere('punch_out_correction_status', 'pending');
            });
    }
}
