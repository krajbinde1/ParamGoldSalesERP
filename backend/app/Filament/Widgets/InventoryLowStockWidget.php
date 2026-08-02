<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\RawMaterials\RawMaterialResource;
use App\Services\Inventory\InventoryDashboardService;
use Filament\Widgets\Widget;

class InventoryLowStockWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = true;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.inventory-low-stock-widget';

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isManagerUser() && ! $user->usesAdminDirectorDashboard() && ! $user->canActAsProductionSupervisor()) {
            return false;
        }

        return RawMaterialResource::canAccess();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $service = app(InventoryDashboardService::class);

        return [
            'rawMaterials' => $service->lowStockRawMaterials(),
            'packagingMaterials' => $service->lowStockPackagingMaterials(),
        ];
    }
}
