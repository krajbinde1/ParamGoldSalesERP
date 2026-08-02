<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\RawMaterials\RawMaterialResource;
use App\Services\Inventory\InventoryDashboardService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InventoryStatsOverviewWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = true;

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return RawMaterialResource::canAccess();
    }

    protected function getStats(): array
    {
        $cards = app(InventoryDashboardService::class)->cards(auth()->user());

        $stats = [
            Stat::make("Today's Production", number_format((float) $cards['today_production_qty'], 2))
                ->description('Units produced today')
                ->color('success'),
            Stat::make('This Month Production', number_format((float) $cards['month_production_qty'], 2))
                ->description('Units produced this month')
                ->color('info'),
            Stat::make('Active Batches', (string) $cards['active_batches'])
                ->description('Draft, checked, or in production')
                ->color('warning'),
            Stat::make('Completed Batches', (string) $cards['completed_batches'])
                ->description('Fully posted batches')
                ->color('success'),
            Stat::make('Low Stock Items', (string) $cards['low_stock_items'])
                ->description('At or below minimum stock')
                ->color($cards['low_stock_items'] > 0 ? 'warning' : 'success'),
            Stat::make('Out of Stock Items', (string) $cards['out_of_stock_items'])
                ->description('Zero available stock')
                ->color($cards['out_of_stock_items'] > 0 ? 'danger' : 'success'),
            Stat::make("Today's Production Cost", '₹'.number_format((float) $cards['today_production_cost'], 2))
                ->description('Total batch cost posted today')
                ->color('gray'),
        ];

        if (isset($cards['raw_material_value'])) {
            $stats[] = Stat::make('Raw Material Stock Value', '₹'.number_format((float) $cards['raw_material_value'], 2))
                ->color('primary');
            $stats[] = Stat::make('Packaging Stock Value', '₹'.number_format((float) $cards['packaging_material_value'], 2))
                ->color('primary');
            $stats[] = Stat::make('Finished Goods Value', '₹'.number_format((float) $cards['finished_goods_value'], 2))
                ->color('primary');
        }

        return $stats;
    }
}
