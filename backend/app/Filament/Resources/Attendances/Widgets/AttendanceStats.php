<?php

namespace App\Filament\Resources\Attendances\Widgets;

use App\Models\Attendance;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AttendanceStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $today = now()->toDateString();

        return [
            Stat::make('Present', Attendance::query()->whereDate('attendance_date', $today)->where('attendance_status', 'Present')->count())->color('success'),
            Stat::make('Absent', Attendance::query()->whereDate('attendance_date', $today)->where('attendance_status', 'Absent')->count())->color('danger'),
            Stat::make('Leave', Attendance::query()->whereDate('attendance_date', $today)->where('attendance_status', 'Leave')->count())->color('info'),
            Stat::make('Pending Approval', Attendance::query()->whereDate('attendance_date', $today)->where('approval_status', 'Pending')->count())->color('warning'),
        ];
    }
}
