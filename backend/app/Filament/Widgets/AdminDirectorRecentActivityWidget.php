<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Collections\CollectionResource;
use App\Filament\Resources\DealerVisits\DealerVisitResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\TaDaClaims\TaDaClaimResource;
use App\Services\Dashboard\AdminDashboardDataService;
use Filament\Widgets\Widget;

class AdminDirectorRecentActivityWidget extends Widget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-recent-activity-widget';

    public string $activeTab = 'orders';

    public static function canView(): bool
    {
        return auth()->user()?->usesAdminDirectorDashboard() ?? false;
    }

    public function setActivityTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $activity = app(AdminDashboardDataService::class)->recentActivity();

        return [
            'activity' => $activity,
            'tabs' => [
                'orders' => ['label' => 'Recent Orders', 'viewUrl' => fn (int $id): string => OrderResource::getUrl('view', ['record' => $id])],
                'collections' => ['label' => 'Recent Collections', 'viewUrl' => fn (int $id): string => CollectionResource::getUrl('view', ['record' => $id])],
                'dealer_visits' => ['label' => 'Recent Dealer Visits', 'viewUrl' => fn (int $id): string => DealerVisitResource::getUrl('view', ['record' => $id])],
                'attendance' => ['label' => 'Recent Attendance', 'viewUrl' => fn (int $id): string => AttendanceResource::getUrl('view', ['record' => $id])],
                'ta_da_claims' => ['label' => 'Recent TA/DA Claims', 'viewUrl' => fn (int $id): string => TaDaClaimResource::getUrl('view', ['record' => $id])],
            ],
        ];
    }
}
