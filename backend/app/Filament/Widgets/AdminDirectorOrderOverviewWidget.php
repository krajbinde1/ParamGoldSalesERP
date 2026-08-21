<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\Dashboard\DirectorDashboardDataService;
use Filament\Widgets\Widget;

class AdminDirectorOrderOverviewWidget extends Widget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-order-overview-widget';

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
        $pipeline = $data['pipeline'];
        $delays = $data['delays'];

        return [
            'stages' => [
                [
                    'label' => 'Placed',
                    'value' => (int) $pipeline['placed'],
                    'stuck' => (int) $delays['pending_24h'] > 0,
                    'url' => OrderResource::getUrl('index', ['tab' => Order::STATUS_PENDING_APPROVAL]),
                ],
                [
                    'label' => 'Approved',
                    'value' => (int) $pipeline['approved'],
                    'stuck' => false,
                    'url' => OrderResource::getUrl('index', ['tab' => Order::STATUS_APPROVED]),
                ],
                [
                    'label' => 'Sent for Bill',
                    'value' => (int) $pipeline['sent_for_bill'],
                    'stuck' => (int) $delays['billing_12h'] > 0,
                    'url' => OrderResource::getUrl('index', ['tab' => Order::STATUS_PENDING_FOR_BILLING]),
                ],
                [
                    'label' => 'Billed',
                    'value' => (int) $pipeline['billed'],
                    'stuck' => (int) $delays['dispatch_24h'] > 0,
                    'url' => OrderResource::getUrl('index', ['tab' => Order::STATUS_BILLED]),
                ],
                [
                    'label' => 'Dispatched',
                    'value' => (int) $pipeline['dispatched'],
                    'stuck' => false,
                    'url' => OrderResource::getUrl('index', ['tab' => Order::STATUS_DISPATCHED]),
                ],
            ],
            'rejected' => [
                'label' => 'Rejected',
                'value' => (int) $pipeline['rejected'],
                'url' => OrderResource::getUrl('index', ['tab' => Order::STATUS_REJECTED]),
            ],
            'delays' => array_values(array_filter([
                (int) $delays['pending_24h'] > 0 ? [
                    'label' => $delays['pending_24h'].' Orders Pending > 24 Hours',
                    'url' => OrderResource::getUrl('index', [
                        'filters' => ['stuck_since' => ['value' => 'pending_24h']],
                    ]),
                ] : null,
                (int) $delays['billing_12h'] > 0 ? [
                    'label' => $delays['billing_12h'].' Orders Waiting for Billing > 12 Hours',
                    'url' => OrderResource::getUrl('index', [
                        'filters' => ['stuck_since' => ['value' => 'billing_12h']],
                    ]),
                ] : null,
                (int) $delays['dispatch_24h'] > 0 ? [
                    'label' => $delays['dispatch_24h'].' Billed Orders Not Dispatched > 24 Hours',
                    'url' => OrderResource::getUrl('index', [
                        'filters' => ['stuck_since' => ['value' => 'dispatch_24h']],
                    ]),
                ] : null,
            ])),
        ];
    }
}
