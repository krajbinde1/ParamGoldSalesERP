<?php

namespace App\Services\Inventory;

use App\Enums\ProductionBatchStatus;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class InventoryDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function cards(?User $user = null): array
    {
        $today = now('Asia/Kolkata')->toDateString();
        $monthStart = now('Asia/Kolkata')->startOfMonth()->toDateString();

        $todayBatchesQuery = ProductionBatch::query()
            ->whereDate('production_date', $today)
            ->where('status', ProductionBatchStatus::Completed);

        $monthBatchesQuery = ProductionBatch::query()
            ->whereDate('production_date', '>=', $monthStart)
            ->where('status', ProductionBatchStatus::Completed);

        $rawMaterialValue = (float) RawMaterial::query()->where('status', true)->sum('current_stock_value');
        $packagingValue = (float) PackagingMaterial::query()->where('status', true)->sum('current_stock_value');
        $semiFinishedValue = (float) SemiFinishedMaterial::query()->where('status', true)->sum('current_stock_value');
        $finishedGoodsValue = (float) Product::query()
            ->where('manufacturing_enabled', true)
            ->where('status', true)
            ->sum(DB::raw('current_finished_stock * weighted_average_cost'));

        $rawMaterialCount = RawMaterial::query()->where('status', true)->count();
        $semiFinishedCount = SemiFinishedMaterial::query()->where('status', true)->count();
        $finishedProductCount = Product::query()
            ->where('manufacturing_enabled', true)
            ->where('status', true)
            ->count();

        $todayEntryCount = (clone $todayBatchesQuery)->count();
        $monthEntryCount = (clone $monthBatchesQuery)->count();
        $todayProductionQty = (float) (clone $todayBatchesQuery)->sum('actual_output_quantity');
        $monthProductionQty = (float) (clone $monthBatchesQuery)->sum('actual_output_quantity');
        $lowStockItems = $this->lowStockCount();

        $todayProducedQty = $this->unitSafeProducedQty(
            (clone $todayBatchesQuery)->with(['product', 'semiFinished'])->get(),
        );

        return [
            // Flat keys — Filament InventoryStatsOverviewWidget + legacy mobile clients.
            'raw_material_value' => $rawMaterialValue,
            'packaging_material_value' => $packagingValue,
            'semi_finished_value' => $semiFinishedValue,
            'finished_goods_value' => $finishedGoodsValue,
            'low_stock_items' => $lowStockItems,
            'out_of_stock_items' => $this->outOfStockCount(),
            'today_production_batches' => $todayEntryCount,
            'today_production_qty' => $todayProductionQty,
            'month_production_batches' => $monthEntryCount,
            'month_production_qty' => $monthProductionQty,
            'month_production_cost' => (float) (clone $monthBatchesQuery)->sum('total_batch_cost'),
            'today_production_cost' => (float) (clone $todayBatchesQuery)->sum('total_batch_cost'),
            'active_batches' => ProductionBatch::query()
                ->whereIn('status', [
                    ProductionBatchStatus::Draft,
                    ProductionBatchStatus::MaterialChecked,
                    ProductionBatchStatus::InProduction,
                ])->count(),
            'completed_batches' => ProductionBatch::query()
                ->where('status', ProductionBatchStatus::Completed)
                ->count(),
            'can_view_costs' => $user?->canViewProductionCosts() ?? false,

            // Nested summary for Production Supervisor mobile Inventory Dashboard.
            'raw_material' => [
                'item_count' => $rawMaterialCount,
                'stock_value' => $rawMaterialValue,
            ],
            'semi_finished' => [
                'item_count' => $semiFinishedCount,
                'stock_value' => $semiFinishedValue,
            ],
            'finished_product' => [
                'item_count' => $finishedProductCount,
                'stock_value' => $finishedGoodsValue,
            ],
            'today_production' => [
                'entry_count' => $todayEntryCount,
                'produced_qty' => $todayProducedQty,
                'produced_qty_unit_safe' => $todayProducedQty !== null,
            ],
            'month_production' => [
                'entry_count' => $monthEntryCount,
            ],
            'low_stock' => [
                'item_count' => $lowStockItems,
            ],
        ];
    }

    /**
     * Sum produced qty only when every completed batch shares the same output unit.
     *
     * @param  Collection<int, ProductionBatch>  $batches
     */
    private function unitSafeProducedQty(Collection $batches): ?float
    {
        if ($batches->isEmpty()) {
            return 0.0;
        }

        $units = $batches
            ->map(fn (ProductionBatch $batch): string => $this->batchOutputUnit($batch))
            ->unique()
            ->values();

        if ($units->count() !== 1 || $units->first() === '') {
            return null;
        }

        return round((float) $batches->sum('actual_output_quantity'), 3);
    }

    private function batchOutputUnit(ProductionBatch $batch): string
    {
        if ($batch->product !== null) {
            return trim((string) ($batch->product->production_unit ?: $batch->product->uom ?: ''));
        }

        if ($batch->semiFinished !== null) {
            return trim((string) ($batch->semiFinished->unit ?: ''));
        }

        return '';
    }

    public function lowStockRawMaterials(int $limit = 10): Collection
    {
        return RawMaterial::query()
            ->where('status', true)
            ->whereColumn('current_stock', '<=', 'minimum_stock')
            ->where('current_stock', '>', 0)
            ->orderBy('current_stock')
            ->limit($limit)
            ->get();
    }

    public function lowStockPackagingMaterials(int $limit = 10): Collection
    {
        return PackagingMaterial::query()
            ->where('status', true)
            ->whereColumn('current_stock', '<=', 'minimum_stock')
            ->where('current_stock', '>', 0)
            ->orderBy('current_stock')
            ->limit($limit)
            ->get();
    }

    public function recentBatches(int $limit = 10): Collection
    {
        return ProductionBatch::query()
            ->with(['product', 'semiFinished', 'supervisor'])
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function productWiseFinishedStock(): Collection
    {
        return Product::query()
            ->where('manufacturing_enabled', true)
            ->where('status', true)
            ->orderBy('product_name')
            ->get(['id', 'product_code', 'product_name', 'current_finished_stock', 'minimum_finished_stock', 'weighted_average_cost', 'latest_production_cost']);
    }

    public function productWiseProductionCost(int $months = 6): Collection
    {
        return ProductionBatch::query()
            ->select([
                'product_id',
                DB::raw('SUM(actual_output_quantity) as total_output'),
                DB::raw('SUM(total_batch_cost) as total_cost'),
                DB::raw('AVG(cost_per_unit) as avg_cost_per_unit'),
            ])
            ->with('product:id,product_name,product_code')
            ->where('status', ProductionBatchStatus::Completed)
            ->where('production_date', '>=', now('Asia/Kolkata')->subMonths($months)->toDateString())
            ->groupBy('product_id')
            ->get();
    }

    public function monthlyProductionTrend(int $months = 6): Collection
    {
        $start = now('Asia/Kolkata')->subMonths($months - 1)->startOfMonth();

        return ProductionBatch::query()
            ->where('status', ProductionBatchStatus::Completed)
            ->where('production_date', '>=', $start->toDateString())
            ->get(['production_date', 'actual_output_quantity', 'total_batch_cost'])
            ->groupBy(fn (ProductionBatch $batch): string => $batch->production_date->format('Y-m'))
            ->map(function (Collection $rows, string $monthKey) {
                return (object) [
                    'month_key' => $monthKey,
                    'total_output' => round((float) $rows->sum('actual_output_quantity'), 3),
                    'total_cost' => round((float) $rows->sum('total_batch_cost'), 2),
                ];
            })
            ->sortKeys()
            ->values();
    }

    public function materialConsumptionTrend(int $days = 30): Collection
    {
        return DB::table('production_batch_consumptions as c')
            ->join('production_batches as b', 'b.id', '=', 'c.production_batch_id')
            ->select([
                'c.material_name',
                'c.item_type',
                DB::raw('SUM(c.consumed_quantity) as total_consumed'),
                DB::raw('SUM(c.consumption_value) as total_value'),
            ])
            ->where('b.status', ProductionBatchStatus::Completed->value)
            ->where('b.production_date', '>=', now('Asia/Kolkata')->subDays($days)->toDateString())
            ->groupBy('c.material_name', 'c.item_type')
            ->orderByDesc('total_consumed')
            ->limit(15)
            ->get();
    }

    private function lowStockCount(): int
    {
        $raw = RawMaterial::query()
            ->where('status', true)
            ->whereColumn('current_stock', '<=', 'minimum_stock')
            ->where('current_stock', '>', 0)
            ->count();

        $pack = PackagingMaterial::query()
            ->where('status', true)
            ->whereColumn('current_stock', '<=', 'minimum_stock')
            ->where('current_stock', '>', 0)
            ->count();

        $semiFinished = SemiFinishedMaterial::query()
            ->where('status', true)
            ->whereColumn('current_stock', '<=', 'minimum_stock')
            ->where('current_stock', '>', 0)
            ->count();

        $finished = Product::query()
            ->where('manufacturing_enabled', true)
            ->where('status', true)
            ->whereColumn('current_finished_stock', '<=', 'minimum_finished_stock')
            ->where('current_finished_stock', '>', 0)
            ->count();

        return $raw + $pack + $semiFinished + $finished;
    }

    private function outOfStockCount(): int
    {
        return RawMaterial::query()->where('status', true)->where('current_stock', '<=', 0)->count()
            + PackagingMaterial::query()->where('status', true)->where('current_stock', '<=', 0)->count()
            + SemiFinishedMaterial::query()->where('status', true)->where('current_stock', '<=', 0)->count()
            + Product::query()->where('manufacturing_enabled', true)->where('status', true)->where('current_finished_stock', '<=', 0)->count();
    }
}
