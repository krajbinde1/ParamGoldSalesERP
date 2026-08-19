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
                    'label' => 'Pending Approval',
                    'value' => Order::query()->where('status', 'pending_approval')->count(),
                    'tone' => 'amber',
                    'icon' => 'heroicon-o-clipboard-document-list',
                    'url' => OrderResource::getUrl('index', ['filters' => ['status' => ['value' => 'pending_approval']]]),
                ],
                [
                    'label' => 'Approved',
                    'value' => Order::query()->where('status', Order::STATUS_APPROVED)->count(),
                    'tone' => 'green',
                    'icon' => 'heroicon-o-check-circle',
                    'url' => OrderResource::getUrl('index', ['filters' => ['status' => ['value' => Order::STATUS_APPROVED]]]),
                ],
                [
                    'label' => 'Billed',
                    'value' => Order::query()->where('status', Order::STATUS_BILLED)->count(),
                    'tone' => 'blue',
                    'icon' => 'heroicon-o-document-text',
                    'url' => OrderResource::getUrl('index', ['filters' => ['status' => ['value' => Order::STATUS_BILLED]]]),
                ],
                [
                    'label' => 'Dispatched',
                    'value' => Order::query()->where('status', Order::STATUS_DISPATCHED)->count(),
                    'tone' => 'teal',
                    'icon' => 'heroicon-o-truck',
                    'url' => OrderResource::getUrl('index', ['filters' => ['status' => ['value' => Order::STATUS_DISPATCHED]]]),
                ],
                [
                    'label' => 'Rejected',
                    'value' => Order::query()->where('status', 'rejected')->count(),
                    'tone' => 'red',
                    'icon' => 'heroicon-o-x-circle',
                    'url' => OrderResource::getUrl('index', ['filters' => ['status' => ['value' => 'rejected']]]),
                ],
            ],
        ];
    }
}
