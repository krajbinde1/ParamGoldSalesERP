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

        $onHoldCount = Order::query()
            ->where('status', Order::STATUS_ON_HOLD)
            ->count();

        $revertedCount = Order::query()
            ->where('status', Order::STATUS_REVERTED_TO_MANAGER)
            ->count();

        $pendingForBillingCount = Order::query()
            ->where('status', Order::STATUS_PENDING_FOR_BILLING)
            ->count();

        $billedCount = Order::query()
            ->where('status', Order::STATUS_BILLED)
            ->count();

        $dispatchedCount = Order::query()
            ->where('status', Order::STATUS_DISPATCHED)
            ->count();

        $rejectedCount = Order::query()
            ->where('status', Order::STATUS_REJECTED)
            ->count();

        return [
            Stat::make('Approved', (string) $approvedCount)
                ->description('Ready to send for bill')
                ->color('success')
                ->url(OrderResource::getUrl('index', [
                    'tab' => Order::STATUS_APPROVED,
                ])),
            Stat::make('On Hold', (string) $onHoldCount)
                ->description('Paused by Production')
                ->color('warning')
                ->url(OrderResource::getUrl('index', [
                    'tab' => Order::STATUS_ON_HOLD,
                ])),
            Stat::make('Returned to Manager', (string) $revertedCount)
                ->description('Waiting for manager re-approval')
                ->color('info')
                ->url(OrderResource::getUrl('index', [
                    'tab' => Order::STATUS_REVERTED_TO_MANAGER,
                ])),
            Stat::make('Sent for Bill', (string) $pendingForBillingCount)
                ->description('Awaiting Admin billing')
                ->color('warning')
                ->url(OrderResource::getUrl('index', [
                    'tab' => Order::STATUS_PENDING_FOR_BILLING,
                ])),
            Stat::make('Billed', (string) $billedCount)
                ->description('Ready for dispatch')
                ->color('info')
                ->url(OrderResource::getUrl('index', [
                    'tab' => Order::STATUS_BILLED,
                ])),
            Stat::make('Dispatched', (string) $dispatchedCount)
                ->description('Completed dispatches')
                ->color('primary')
                ->url(OrderResource::getUrl('index', [
                    'tab' => Order::STATUS_DISPATCHED,
                ])),
            Stat::make('Rejected', (string) $rejectedCount)
                ->description('Rejected orders')
                ->color('danger')
                ->url(OrderResource::getUrl('index', [
                    'tab' => Order::STATUS_REJECTED,
                ])),
        ];
    }
}
