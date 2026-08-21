<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Widgets\Widget;

class ManagerOrderStatsWidget extends Widget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.manager-order-stats-widget';

    public static function canView(): bool
    {
        return auth()->user()?->usesManagerDashboard() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'stats' => [
                [
                    'label' => 'Pending for Approval',
                    'value' => Order::query()->where('status', 'pending_approval')->count(),
                    'description' => 'Awaiting manager action',
                    'color' => 'warning',
                    'url' => OrderResource::getUrl('index', [
                        'filters' => [
                            'status' => [
                                'value' => 'pending_approval',
                            ],
                        ],
                    ]),
                ],
                [
                    'label' => 'Returned by Production',
                    'value' => Order::query()->where('status', Order::STATUS_REVERTED_TO_MANAGER)->count(),
                    'description' => 'Needs manager re-approval',
                    'color' => 'warning',
                    'url' => OrderResource::getUrl('index', [
                        'tab' => Order::STATUS_REVERTED_TO_MANAGER,
                    ]),
                ],
                [
                    'label' => 'Approved Orders',
                    'value' => Order::query()->where('status', 'approved')->count(),
                    'description' => 'Approved and ready',
                    'color' => 'success',
                    'url' => OrderResource::getUrl('index', [
                        'filters' => [
                            'status' => [
                                'value' => 'approved',
                            ],
                        ],
                    ]),
                ],
                [
                    'label' => 'Dispatched Orders',
                    'value' => Order::query()->where('status', Order::STATUS_DISPATCHED)->count(),
                    'description' => 'Completed dispatches',
                    'color' => 'info',
                    'url' => OrderResource::getUrl('index', [
                        'filters' => [
                            'status' => [
                                'value' => Order::STATUS_DISPATCHED,
                            ],
                        ],
                    ]),
                ],
            ],
        ];
    }
}
