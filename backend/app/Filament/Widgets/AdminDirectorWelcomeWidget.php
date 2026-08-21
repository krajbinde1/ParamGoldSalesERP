<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\DealerVisits\DealerVisitResource;
use App\Filament\Resources\FieldActivities\FieldActivityResource;
use App\Services\Attendance\AttendanceStatusCalculator;
use App\Services\Dashboard\AdminDashboardDataService;
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
        $counts = app(AdminDashboardDataService::class)->teamTodayCounts();
        $today = $counts['today'];

        return [
            'userName' => $user?->employee?->full_name ?? $user?->name ?? 'User',
            'roleLabel' => $user?->adminDirectorRoleLabel() ?? 'Admin',
            'currentDate' => $now->format('l, d F Y'),
            'teamToday' => [
                [
                    'label' => 'Punched In',
                    'value' => $counts['punched_in'],
                    'tone' => 'teal',
                    'icon' => 'heroicon-o-finger-print',
                    'url' => AttendanceResource::getUrl('index', [
                        'filters' => ['punched_in' => ['isActive' => true]],
                    ]),
                ],
                [
                    'label' => 'Present',
                    'value' => $counts['present'],
                    'tone' => 'green',
                    'icon' => 'heroicon-o-check-circle',
                    'url' => AttendanceResource::getUrl('index', [
                        'filters' => ['attendance_status' => ['value' => AttendanceStatusCalculator::STATUS_PRESENT]],
                    ]),
                ],
                [
                    'label' => 'Absent',
                    'value' => $counts['absent'],
                    'tone' => 'red',
                    'icon' => 'heroicon-o-user-minus',
                    'url' => AttendanceResource::getUrl('index', [
                        'filters' => ['attendance_status' => ['value' => AttendanceStatusCalculator::STATUS_ABSENT]],
                    ]),
                ],
                [
                    'label' => 'Half Day',
                    'value' => $counts['half_day'],
                    'tone' => 'amber',
                    'icon' => 'heroicon-o-clock',
                    'url' => AttendanceResource::getUrl('index', [
                        'filters' => ['attendance_status' => ['value' => AttendanceStatusCalculator::STATUS_HALF_DAY]],
                    ]),
                ],
                [
                    'label' => 'Dealer Visits',
                    'value' => $counts['dealer_visits'],
                    'tone' => 'blue',
                    'icon' => 'heroicon-o-building-storefront',
                    'url' => DealerVisitResource::getUrl('index', [
                        'filters' => ['visit_date' => ['date' => $today]],
                    ]),
                ],
                [
                    'label' => 'Field Visits',
                    'value' => $counts['field_visits'],
                    'tone' => 'teal',
                    'icon' => 'heroicon-o-map-pin',
                    'url' => FieldActivityResource::getUrl('index', [
                        'filters' => ['activity_date' => ['date' => $today]],
                    ]),
                ],
            ],
        ];
    }
}
