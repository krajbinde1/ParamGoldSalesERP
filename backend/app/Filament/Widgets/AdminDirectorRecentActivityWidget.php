<?php

namespace App\Filament\Widgets;

use App\Services\Dashboard\DirectorDashboardDataService;
use Filament\Widgets\Widget;

class AdminDirectorRecentActivityWidget extends Widget
{
    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-recent-activity-widget';

    public static function canView(): bool
    {
        return auth()->user()?->usesAdminDirectorDashboard() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'activities' => app(DirectorDashboardDataService::class)->snapshot()['recent_activity'],
        ];
    }
}
