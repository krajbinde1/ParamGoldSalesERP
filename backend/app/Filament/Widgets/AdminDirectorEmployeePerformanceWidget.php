<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ManagerEmployeePerformanceDetail;
use App\Services\Dashboard\DirectorDashboardDataService;
use Filament\Widgets\Widget;

class AdminDirectorEmployeePerformanceWidget extends Widget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-employee-performance-widget';

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

        return [
            'topPerformers' => $data['team_performance']['top'],
            'needsAttention' => $data['team_performance']['needs_attention'],
            'detailUrl' => fn (int $employeeId): string => ManagerEmployeePerformanceDetail::getUrl([
                'employee' => $employeeId,
            ]),
        ];
    }
}
