<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Dealers\DealerResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use Filament\Widgets\Widget;

class AdminDirectorQuickActionsWidget extends Widget
{
    protected static ?int $sort = 7;

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
        $actions = array_values(array_filter([
            DealerResource::canCreate()
                ? ['label' => 'Add Dealer', 'url' => DealerResource::getUrl('create'), 'tone' => 'teal']
                : null,
            EmployeeResource::canCreate()
                ? ['label' => 'Add Employee', 'url' => EmployeeResource::getUrl('create'), 'tone' => 'green']
                : null,
            PaymentRequestResource::canAccess()
                ? ['label' => 'Create Payment Request', 'url' => PaymentRequestResource::getUrl('create'), 'tone' => 'amber']
                : null,
            OrderResource::canViewAny()
                ? ['label' => 'View Orders', 'url' => OrderResource::getUrl('index'), 'tone' => 'blue']
                : null,
            PaymentRequestResource::canAccess()
                ? ['label' => 'Payment Requests', 'url' => PaymentRequestResource::getUrl('index'), 'tone' => 'slate']
                : null,
        ]));

        return [
            'actions' => array_slice($actions, 0, 5),
        ];
    }
}
