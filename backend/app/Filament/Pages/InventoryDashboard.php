<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\InventoryFilamentAccess;
use App\Filament\Widgets\InventoryLowStockWidget;
use App\Filament\Widgets\InventoryRecentBatchesWidget;
use App\Filament\Widgets\InventoryStatsOverviewWidget;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class InventoryDashboard extends Page
{
    use InventoryFilamentAccess;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Inventory Dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $title = 'Inventory Dashboard';

    protected static ?string $slug = 'inventory-dashboard';

    /**
     * Managers get a limited, read-only summary (stats only); directors,
     * admins, and production supervisors see the full operational view.
     *
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        $widgets = [
            InventoryStatsOverviewWidget::class,
        ];

        $user = auth()->user();

        if ($user?->isManagerUser() && ! $user->usesAdminDirectorDashboard() && ! $user->canActAsProductionSupervisor()) {
            return $widgets;
        }

        $widgets[] = InventoryLowStockWidget::class;
        $widgets[] = InventoryRecentBatchesWidget::class;

        return $widgets;
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 4;
    }
}
