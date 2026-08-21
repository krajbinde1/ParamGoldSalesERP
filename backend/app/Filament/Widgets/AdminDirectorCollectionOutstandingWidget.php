<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Collections\CollectionResource;
use App\Filament\Resources\Dealers\DealerResource;
use App\Services\Dashboard\DirectorDashboardDataService;
use Filament\Widgets\Widget;

class AdminDirectorCollectionOutstandingWidget extends Widget
{
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-collection-outstanding-widget';

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
        $format = DirectorDashboardDataService::formatCompact(...);

        return [
            'cards' => [
                [
                    'label' => "Today's Collection",
                    'value' => $format((float) $data['today_collection']),
                    'url' => CollectionResource::getUrl('index', [
                        'filters' => ['collection_date' => ['date' => $today]],
                    ]),
                ],
                [
                    'label' => 'This Month Collection',
                    'value' => $format((float) $data['month_collection']),
                    'url' => CollectionResource::getUrl('index', [
                        'filters' => ['this_month_received' => ['isActive' => true]],
                    ]),
                ],
                [
                    'label' => 'Total Outstanding',
                    'value' => $format((float) $data['total_outstanding']),
                    'url' => DealerResource::getUrl('index'),
                ],
                [
                    'label' => 'High Outstanding Dealers',
                    'value' => (string) $data['high_outstanding_dealers'],
                    'url' => DealerResource::getUrl('index', [
                        'filters' => ['high_outstanding' => ['isActive' => true]],
                    ]),
                ],
            ],
        ];
    }
}
