<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Resources\Production\ProductionBatchPresenter;
use App\Models\ProductionBatch;
use App\Models\User;
use App\Services\Inventory\InventoryDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryDashboardApiController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly InventoryDashboardService $dashboardService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok('Inventory dashboard loaded successfully.', self::buildPayload($this->dashboardService, $user));
    }

    /**
     * Shared payload builder so other dashboards (e.g. the sales/orders production
     * dashboard) can embed the same inventory summary under an `inventory` key.
     *
     * @return array<string, mixed>
     */
    public static function buildPayload(InventoryDashboardService $dashboardService, ?User $user): array
    {
        // Same card payload as Filament InventoryStatsOverviewWidget /
        // InventoryDashboardService::cards — do not strip valuation fields here.
        // Per-screen cost gating (masters rates, report columns) stays in those APIs.
        $cards = $dashboardService->cards($user);
        $canViewCosts = $user?->canViewProductionCosts() ?? false;
        $cards['can_view_costs'] = $canViewCosts;

        $recentBatches = $dashboardService->recentBatches(5)
            ->map(fn (ProductionBatch $batch): array => $user !== null
                ? ProductionBatchPresenter::summary($batch, $user)
                : [
                    'id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'status' => $batch->status->value,
                ])
            ->values()
            ->all();

        $quickActions = [
            ['key' => 'stock_report', 'label' => 'Inventory Stock Report', 'route' => 'stock-report'],
            ['key' => 'new_production', 'label' => 'New Production Entry', 'route' => 'entry'],
            ['key' => 'production_history', 'label' => 'Production History', 'route' => 'history'],
            ['key' => 'stock_ledger', 'label' => 'Stock Ledger / Ledger Reports', 'route' => 'stock-ledger'],
        ];

        if ($user?->canAdjustStock()) {
            $quickActions[] = [
                'key' => 'stock_adjustment',
                'label' => 'Stock Adjustment',
                'route' => 'stock-ledger',
            ];
        }

        return [
            'cards' => $cards,
            'recent_batches' => $recentBatches,
            'can_view_costs' => $canViewCosts,
            'can_adjust_stock' => $user?->canAdjustStock() ?? false,
            'quick_actions' => $quickActions,
        ];
    }
}
