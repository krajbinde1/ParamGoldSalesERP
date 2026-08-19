<?php

namespace App\Filament\Widgets;

use App\Models\Attendance;
use App\Models\DealerVisit;
use App\Models\FieldActivity;
use App\Services\Attendance\AttendanceStatusCalculator;
use App\Support\AttendanceCalendar;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class AdminDirectorWelcomeWidget extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-welcome-widget';

    public static function canView(): bool
    {
        return auth()->user()?->usesAdminDirectorDashboard() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Filament::auth()->user();
        $now = Carbon::now(AttendanceCalendar::TIMEZONE);
        $today = $now->toDateString();

        $presentToday = Attendance::query()
            ->whereDate('attendance_date', $today)
            ->where('attendance_status', AttendanceStatusCalculator::STATUS_PRESENT)
            ->count();
        $halfDayToday = Attendance::query()
            ->whereDate('attendance_date', $today)
            ->where('attendance_status', AttendanceStatusCalculator::STATUS_HALF_DAY)
            ->count();
        $absentToday = Attendance::query()
            ->whereDate('attendance_date', $today)
            ->where('attendance_status', AttendanceStatusCalculator::STATUS_ABSENT)
            ->count();
        $dealerVisitsToday = DealerVisit::query()->whereDate('visit_date', $today)->count();
        $fieldVisitsToday = FieldActivity::query()->whereDate('activity_date', $today)->count();

        return [
            'userName' => $user?->employee?->full_name ?? $user?->name ?? 'User',
            'roleLabel' => $user?->adminDirectorRoleLabel() ?? 'Admin',
            'currentDate' => $now->format('l, d F Y'),
            'teamToday' => [
                ['label' => 'Present', 'value' => $presentToday, 'tone' => 'green', 'icon' => 'heroicon-o-check-circle'],
                ['label' => 'Absent', 'value' => $absentToday, 'tone' => 'red', 'icon' => 'heroicon-o-user-minus'],
                ['label' => 'Half Day', 'value' => $halfDayToday, 'tone' => 'amber', 'icon' => 'heroicon-o-clock'],
                ['label' => 'Dealer Visits', 'value' => $dealerVisitsToday, 'tone' => 'blue', 'icon' => 'heroicon-o-building-storefront'],
                ['label' => 'Field Visits', 'value' => $fieldVisitsToday, 'tone' => 'teal', 'icon' => 'heroicon-o-map-pin'],
            ],
        ];
    }
}
