<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Widgets\Widget;

class AdminDirectorOrderOverviewWidget extends Widget
{
    protected static ?int $sort = 3;

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
        return [
            'stats' => [
                [
                    'label' => 'Pending for Approval',
                    'value' => Order::query()->where('status', 'pending_approval')->count(),
                    'tone' => 'amber',
                    'url' => OrderResource::getUrl('index', ['filters' => ['status' => ['value' => 'pending_approval']]]),
                ],
                [
                    'label' => 'Approved',
                    'value' => Order::query()->where('status', Order::STATUS_APPROVED)->count(),
                    'tone' => 'green',
                    'url' => OrderResource::getUrl('index', ['filters' => ['status' => ['value' => Order::STATUS_APPROVED]]]),
                ],
                [
                    'label' => 'Billed',
                    'value' => Order::query()->where('status', Order::STATUS_BILLED)->count(),
                    'tone' => 'blue',
                    'url' => OrderResource::getUrl('index', ['filters' => ['status' => ['value' => Order::STATUS_BILLED]]]),
                ],
                [
                    'label' => 'Dispatched',
                    'value' => Order::query()->where('status', Order::STATUS_DISPATCHED)->count(),
                    'tone' => 'teal',
                    'url' => OrderResource::getUrl('index', ['filters' => ['status' => ['value' => Order::STATUS_DISPATCHED]]]),
                ],
                [
                    'label' => 'Rejected',
                    'value' => Order::query()->where('status', 'rejected')->count(),
                    'tone' => 'red',
                    'url' => OrderResource::getUrl('index', ['filters' => ['status' => ['value' => 'rejected']]]),
                ],
            ],
        ];
    }
}
