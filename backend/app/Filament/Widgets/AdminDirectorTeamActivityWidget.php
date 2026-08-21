<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\DealerVisits\DealerVisitResource;
use App\Filament\Resources\EmployeeRoutes\EmployeeRouteResource;
use App\Filament\Resources\FieldActivities\FieldActivityResource;
use App\Services\Dashboard\DirectorDashboardDataService;
use Filament\Widgets\Widget;

class AdminDirectorTeamActivityWidget extends Widget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-team-activity-widget';

    public static function canView(): bool
    {
        return auth()->user()?->usesAdminDirectorDashboard() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $data = app(DirectorDashboardDataService::class)->snapshot();
        $today = $data['today'];

        return [
            'metrics' => [
                [
                    'label' => 'Employees',
                    'value' => (string) $data['active_employees'],
                    'url' => AttendanceResource::getUrl('index'),
                ],
                [
                    'label' => 'Punched In',
                    'value' => (string) $data['punched_in'],
                    'url' => AttendanceResource::getUrl('index', [
                        'filters' => ['punched_in' => ['isActive' => true]],
                    ]),
                ],
                [
                    'label' => 'Not Punched In',
                    'value' => (string) $data['not_punched_in'],
                    'url' => AttendanceResource::getUrl('index'),
                ],
                [
                    'label' => 'Dealer Visits',
                    'value' => (string) $data['dealer_visits'],
                    'url' => DealerVisitResource::getUrl('index', [
                        'filters' => ['visit_date' => ['date' => $today]],
                    ]),
                ],
                [
                    'label' => 'Field Visits',
                    'value' => (string) $data['field_visits'],
                    'url' => FieldActivityResource::getUrl('index', [
                        'filters' => ['activity_date' => ['date' => $today]],
                    ]),
                ],
                [
                    'label' => 'Active Routes',
                    'value' => (string) $data['active_routes'],
                    'url' => EmployeeRouteResource::getUrl('index', [
                        'filters' => [
                            'attendance_date' => ['date' => $today],
                            'active_now' => ['isActive' => true],
                        ],
                    ]),
                ],
            ],
        ];
    }
}
