<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProductionOrderStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->usesProductionSupervisorDashboard() ?? false;
    }

    protected function getStats(): array
    {
        $approvedCount = Order::query()
            ->where('status', Order::STATUS_APPROVED)
            ->count();

        $billedCount = Order::query()
            ->where('status', Order::STATUS_BILLED)
            ->count();

        $dispatchedCount = Order::query()
            ->where('status', Order::STATUS_DISPATCHED)
            ->count();

        return [
            Stat::make('Approved Orders', (string) $approvedCount)
                ->description('Ready for production planning')
                ->color('success')
                ->url(OrderResource::getUrl('index', [
                    'filters' => [
                        'status' => [
                            'value' => Order::STATUS_APPROVED,
                        ],
                    ],
                ])),
            Stat::make('Billed Orders', (string) $billedCount)
                ->description('Ready for dispatch')
                ->color('warning')
                ->url(OrderResource::getUrl('index', [
                    'filters' => [
                        'status' => [
                            'value' => Order::STATUS_BILLED,
                        ],
                    ],
                ])),
            Stat::make('Dispatched Orders', (string) $dispatchedCount)
                ->description('Completed dispatches')
                ->color('info')
                ->url(OrderResource::getUrl('index', [
                    'filters' => [
                        'status' => [
                            'value' => Order::STATUS_DISPATCHED,
                        ],
                    ],
                ])),
        ];
    }
}
