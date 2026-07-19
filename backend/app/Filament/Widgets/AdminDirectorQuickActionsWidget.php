<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Collections\CollectionResource;
use App\Filament\Resources\Dealers\DealerResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\TaDaClaims\TaDaClaimResource;
use App\Filament\Resources\Targets\WeeklyTargetResource;
use Filament\Widgets\Widget;

class AdminDirectorQuickActionsWidget extends Widget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-quick-actions-widget';

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
            'actions' => array_values(array_filter([
                EmployeeResource::canCreate() ? ['label' => 'Add Employee', 'url' => EmployeeResource::getUrl('create'), 'color' => 'success'] : null,
                DealerResource::canCreate() ? ['label' => 'Add Dealer', 'url' => DealerResource::getUrl('create'), 'color' => 'info'] : null,
                ProductResource::canCreate() ? ['label' => 'Add Product', 'url' => ProductResource::getUrl('create'), 'color' => 'info'] : null,
                OrderResource::canViewAny() ? ['label' => 'View Orders', 'url' => OrderResource::getUrl('index'), 'color' => 'primary'] : null,
                CollectionResource::canViewAny() ? ['label' => 'View Collections', 'url' => CollectionResource::getUrl('index'), 'color' => 'primary'] : null,
                WeeklyTargetResource::canViewAny() ? ['label' => 'Manage Targets', 'url' => WeeklyTargetResource::getUrl('index'), 'color' => 'warning'] : null,
                TaDaClaimResource::canViewAny() ? ['label' => 'Review TA/DA Claims', 'url' => TaDaClaimResource::getUrl('index'), 'color' => 'warning'] : null,
            ])),
        ];
    }
}
