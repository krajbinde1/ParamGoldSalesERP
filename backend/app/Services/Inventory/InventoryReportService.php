<?php

namespace App\Services\Inventory;

use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Unified Inventory Stock Report (Raw Material + Packaging Material +
 * Semi Finished + Finished Product) in a single dataset with an inventory-type
 * discriminator.
 *
 * Reuses stored stock fields already maintained by the inward/production/posting
 * services (current_stock, average_rate, current_stock_value, current_finished_stock,
 * weighted_average_cost, etc.) — it never recalculates stock, WAC, or valuation.
 */
final class InventoryReportService
{
    public const TYPE_ALL = 'all';

    public const TYPE_RAW_MATERIAL = 'raw_material';

    public const TYPE_PACKAGING_MATERIAL = 'packaging_material';

    public const TYPE_SEMI_FINISHED = 'semi_finished';

    public const TYPE_FINISHED_PRODUCT = 'finished_product';

    /**
     * @return array<string, string>
     */
    public static function inventoryTypeOptions(): array
    {
        return [
            self::TYPE_ALL => 'All',
            self::TYPE_RAW_MATERIAL => 'Raw Material',
            self::TYPE_PACKAGING_MATERIAL => 'Packaging Material',
            self::TYPE_SEMI_FINISHED => 'Semi Finished',
            self::TYPE_FINISHED_PRODUCT => 'Finished Product',
        ];
    }

    public static function inventoryTypeLabel(string $key): string
    {
        return match ($key) {
            self::TYPE_RAW_MATERIAL => 'Raw Material',
            self::TYPE_PACKAGING_MATERIAL => 'Packaging Material',
            self::TYPE_SEMI_FINISHED => 'Semi Finished',
            self::TYPE_FINISHED_PRODUCT => 'Finished Product',
            default => $key !== '' ? $key : '—',
        };
    }

    /**
     * Item / Product dropdown options, filtered by the selected inventory type.
     * Option keys are "{type}:{id}" composite strings since ids collide across models.
     *
     * @return array<string, string>
     */
    public function itemOptions(?string $inventoryType): array
    {
        $type = $inventoryType ?: self::TYPE_ALL;

        $options = [];

        if ($type === self::TYPE_ALL || $type === self::TYPE_RAW_MATERIAL) {
            foreach (
                RawMaterial::query()->orderBy('material_name')->get(['id', 'material_code', 'material_name']) as $material
            ) {
                $options[self::TYPE_RAW_MATERIAL.':'.$material->id] = trim(($material->material_code ? $material->material_code.' — ' : '').$material->material_name);
            }
        }

        if ($type === self::TYPE_ALL || $type === self::TYPE_PACKAGING_MATERIAL) {
            foreach (
                PackagingMaterial::query()->orderBy('packaging_name')->get(['id', 'packaging_code', 'packaging_name']) as $material
            ) {
                $options[self::TYPE_PACKAGING_MATERIAL.':'.$material->id] = trim(($material->packaging_code ? $material->packaging_code.' — ' : '').$material->packaging_name);
            }
        }

        if ($type === self::TYPE_ALL || $type === self::TYPE_SEMI_FINISHED) {
            foreach (
                SemiFinishedMaterial::query()->orderBy('material_name')->get(['id', 'material_code', 'material_name']) as $material
            ) {
                $options[self::TYPE_SEMI_FINISHED.':'.$material->id] = trim(($material->material_code ? $material->material_code.' — ' : '').$material->material_name);
            }
        }

        if ($type === self::TYPE_ALL || $type === self::TYPE_FINISHED_PRODUCT) {
            foreach (
                Product::query()->inFinishedInventory()->orderBy('product_name')->get(['id', 'product_code', 'product_name']) as $product
            ) {
                $options[self::TYPE_FINISHED_PRODUCT.':'.$product->id] = $product->displayLabel();
            }
        }

        return $options;
    }

    /**
     * @param  array{
     *     inventory_type?: string|null,
     *     item_key?: string|null,
     *     search?: string|null,
     *     stock_status_filter?: string|null,
     * }  $filters
     */
    public function build(array $filters): InventoryReportResult
    {
        $type = (string) ($filters['inventory_type'] ?? self::TYPE_ALL);
        if (! array_key_exists($type, self::inventoryTypeOptions())) {
            $type = self::TYPE_ALL;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        [$itemType, $itemId] = self::parseItemKey($filters['item_key'] ?? null);
        $statusFilter = $filters['stock_status_filter'] ?? null;

        $summaryCards = $this->summaryCards($type);
        $appliedFilterLabels = $this->appliedFilterLabels($type, $filters['item_key'] ?? null, $search, $statusFilter);

        $includeSource = fn (string $sourceType): bool => ($type === self::TYPE_ALL || $type === $sourceType)
            && ($itemType === null || $itemType === $sourceType);

        $parts = [];

        if ($includeSource(self::TYPE_RAW_MATERIAL)) {
            $parts[] = $this->rawMaterialSubquery($search, $itemId);
        }

        if ($includeSource(self::TYPE_PACKAGING_MATERIAL)) {
            $parts[] = $this->packagingMaterialSubquery($search, $itemId);
        }

        if ($includeSource(self::TYPE_SEMI_FINISHED)) {
            $parts[] = $this->semiFinishedSubquery($search, $itemId);
        }

        if ($includeSource(self::TYPE_FINISHED_PRODUCT)) {
            $parts[] = $this->finishedProductSubquery($search, $itemId);
        }

        if ($parts === []) {
            return $this->buildResult($this->emptyStockQuery(), $summaryCards, $appliedFilterLabels);
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union = $union->unionAll($part);
        }

        $query = DB::query()->fromSub($union, 'stock_rows');

        if ($statusFilter === 'out_of_stock') {
            $query->where('current_stock', '<=', 0);
        } elseif ($statusFilter === 'low_stock') {
            $query->where('current_stock', '>', 0)->whereColumn('current_stock', '<=', 'minimum_stock');
        } elseif (in_array($statusFilter, ['in_stock', 'available'], true)) {
            $query->whereColumn('current_stock', '>', 'minimum_stock');
        }

        return $this->buildResult($query, $summaryCards, $appliedFilterLabels);
    }

    /**
     * Summary KPIs. Value cards stay global navigational totals.
     * Low / Out counts are scoped to the selected inventory type when not "All".
     *
     * @return list<array{key: string, label: string, value: string, tone: string, clickable: bool, filter: string}>
     */
    public function summaryCards(?string $inventoryType = null): array
    {
        $type = $inventoryType && array_key_exists($inventoryType, self::inventoryTypeOptions())
            ? $inventoryType
            : self::TYPE_ALL;

        $rawValue = (float) RawMaterial::query()->where('status', true)->sum('current_stock_value');
        $packagingValue = (float) PackagingMaterial::query()->where('status', true)->sum('current_stock_value');
        $semiFinishedValue = (float) SemiFinishedMaterial::query()->where('status', true)->sum('current_stock_value');
        $finishedValue = (float) Product::query()
            ->where('status', true)
            ->inFinishedInventory()
            ->sum(DB::raw('current_finished_stock * weighted_average_cost'));

        $totalValue = $rawValue + $packagingValue + $semiFinishedValue + $finishedValue;

        $lowStockCount = $this->lowStockCount($type);
        $outOfStockCount = $this->outOfStockCount($type);

        return [
            [
                'key' => 'total_value',
                'label' => 'Total Stock Value',
                'value' => $this->formatMoney($totalValue),
                'tone' => 'primary',
                'clickable' => true,
                'filter' => 'total',
            ],
            [
                'key' => 'raw_material_value',
                'label' => 'Raw Material Value',
                'value' => $this->formatMoney($rawValue),
                'tone' => 'info',
                'clickable' => true,
                'filter' => self::TYPE_RAW_MATERIAL,
            ],
            [
                'key' => 'packaging_material_value',
                'label' => 'Packaging Material Value',
                'value' => $this->formatMoney($packagingValue),
                'tone' => 'warning',
                'clickable' => true,
                'filter' => self::TYPE_PACKAGING_MATERIAL,
            ],
            [
                'key' => 'semi_finished_value',
                'label' => 'Semi-Finished Value',
                'value' => $this->formatMoney($semiFinishedValue),
                'tone' => 'info',
                'clickable' => true,
                'filter' => self::TYPE_SEMI_FINISHED,
            ],
            [
                'key' => 'finished_product_value',
                'label' => 'Finished Product Value',
                'value' => $this->formatMoney($finishedValue),
                'tone' => 'success',
                'clickable' => true,
                'filter' => self::TYPE_FINISHED_PRODUCT,
            ],
            [
                'key' => 'low_stock',
                'label' => 'Low Stock Items',
                'value' => number_format($lowStockCount),
                'tone' => 'warning',
                'clickable' => true,
                'filter' => 'low_stock',
            ],
            [
                'key' => 'out_of_stock',
                'label' => 'Out Of Stock Items',
                'value' => number_format($outOfStockCount),
                'tone' => 'danger',
                'clickable' => true,
                'filter' => 'out_of_stock',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $summaryCards
     * @param  list<string>  $appliedFilterLabels
     */
    protected function buildResult(EloquentBuilder|QueryBuilder $query, array $summaryCards, array $appliedFilterLabels): InventoryReportResult
    {
        return new InventoryReportResult(
            title: 'Inventory Stock Report',
            filenameStem: 'Inventory_Stock_Report',
            columns: $this->stockColumns(),
            summaryCards: $summaryCards,
            appliedFilterLabels: $appliedFilterLabels,
            query: $query,
            rowMapper: function (object $row, int $sr): array {
                $qty = (float) $row->current_stock;
                $min = (float) $row->minimum_stock;

                return [
                    $sr,
                    $row->name,
                    self::inventoryTypeLabel((string) $row->inventory_type_key),
                    $row->unit,
                    $qty,
                    (float) $row->average_rate,
                    (float) $row->stock_value,
                    $this->stockStatusKey($qty, $min),
                ];
            },
            defaultSort: 'name',
            defaultSortDirection: 'asc',
            footerStockValue: fn (EloquentBuilder|QueryBuilder $q): float => (float) $q->sum('stock_value'),
            footerBreakdown: function (EloquentBuilder|QueryBuilder $q): array {
                $rows = (clone $q)
                    ->select('inventory_type_key', DB::raw('SUM(stock_value) as total'))
                    ->groupBy('inventory_type_key')
                    ->pluck('total', 'inventory_type_key');

                return [
                    self::TYPE_RAW_MATERIAL => (float) ($rows[self::TYPE_RAW_MATERIAL] ?? 0),
                    self::TYPE_PACKAGING_MATERIAL => (float) ($rows[self::TYPE_PACKAGING_MATERIAL] ?? 0),
                    self::TYPE_SEMI_FINISHED => (float) ($rows[self::TYPE_SEMI_FINISHED] ?? 0),
                    self::TYPE_FINISHED_PRODUCT => (float) ($rows[self::TYPE_FINISHED_PRODUCT] ?? 0),
                ];
            },
        );
    }

    /**
     * @return array{key: string, label: string, align: string, format: string, sortable: string|false}
     */
    protected function col(string $key, string $label, string $align, string $format, string|false $sortable): array
    {
        return compact('key', 'label', 'align', 'format', 'sortable');
    }

    /**
     * @return list<array{key: string, label: string, align: string, format: string, sortable: string|false}>
     */
    protected function stockColumns(): array
    {
        return [
            $this->col('sr_no', 'Sr No.', 'right', 'integer', false),
            $this->col('item_name', 'Item Name', 'left', 'text', 'name'),
            $this->col('inventory_type', 'Inventory Type', 'left', 'badge_type', 'inventory_type_key'),
            $this->col('unit', 'Unit', 'left', 'text', false),
            $this->col('current_stock', 'Current Stock', 'right', 'qty', 'current_stock'),
            $this->col('average_rate', 'Average Rate', 'right', 'rate', 'average_rate'),
            $this->col('stock_value', 'Stock Value', 'right', 'money', 'stock_value'),
            $this->col('stock_status', 'Stock Status', 'left', 'badge_stock', false),
        ];
    }

    protected function rawMaterialSubquery(string $search, ?int $itemId): EloquentBuilder
    {
        return RawMaterial::query()
            ->selectRaw("'".self::TYPE_RAW_MATERIAL."' as inventory_type_key, id as item_id, material_code as code, material_name as name, unit, current_stock, minimum_stock, average_rate, current_stock_value as stock_value")
            ->where('status', true)
            ->when($itemId, fn ($q) => $q->whereKey($itemId))
            ->when($search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('material_name', 'like', '%'.$search.'%')
                ->orWhere('material_code', 'like', '%'.$search.'%')));
    }

    protected function packagingMaterialSubquery(string $search, ?int $itemId): EloquentBuilder
    {
        return PackagingMaterial::query()
            ->selectRaw("'".self::TYPE_PACKAGING_MATERIAL."' as inventory_type_key, id as item_id, packaging_code as code, packaging_name as name, unit, current_stock, minimum_stock, average_rate, current_stock_value as stock_value")
            ->where('status', true)
            ->when($itemId, fn ($q) => $q->whereKey($itemId))
            ->when($search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('packaging_name', 'like', '%'.$search.'%')
                ->orWhere('packaging_code', 'like', '%'.$search.'%')));
    }

    protected function semiFinishedSubquery(string $search, ?int $itemId): EloquentBuilder
    {
        return SemiFinishedMaterial::query()
            ->selectRaw("'".self::TYPE_SEMI_FINISHED."' as inventory_type_key, id as item_id, material_code as code, material_name as name, unit, current_stock, minimum_stock, average_production_cost as average_rate, current_stock_value as stock_value")
            ->where('status', true)
            ->when($itemId, fn ($q) => $q->whereKey($itemId))
            ->when($search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('material_name', 'like', '%'.$search.'%')
                ->orWhere('material_code', 'like', '%'.$search.'%')));
    }

    protected function finishedProductSubquery(string $search, ?int $itemId): EloquentBuilder
    {
        return Product::query()
            ->selectRaw("'".self::TYPE_FINISHED_PRODUCT."' as inventory_type_key, id as item_id, product_code as code, product_name as name, COALESCE(production_unit, uom) as unit, current_finished_stock as current_stock, minimum_finished_stock as minimum_stock, weighted_average_cost as average_rate, ROUND(current_finished_stock * weighted_average_cost, 2) as stock_value")
            ->where('status', true)
            ->inFinishedInventory()
            ->when($itemId, fn ($q) => $q->whereKey($itemId))
            ->when($search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('product_name', 'like', '%'.$search.'%')
                ->orWhere('product_code', 'like', '%'.$search.'%')));
    }

    /**
     * A query shaped exactly like the unified stock rows but guaranteed to return
     * zero records — used when no inventory sources match the active filters.
     */
    protected function emptyStockQuery(): QueryBuilder
    {
        $empty = RawMaterial::query()
            ->selectRaw("'".self::TYPE_SEMI_FINISHED."' as inventory_type_key, id as item_id, material_code as code, material_name as name, unit, current_stock, minimum_stock, average_rate, current_stock_value as stock_value")
            ->whereRaw('1 = 0');

        return DB::query()->fromSub($empty, 'stock_rows');
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    protected static function parseItemKey(mixed $itemKey): array
    {
        if (! is_string($itemKey) || $itemKey === '' || ! str_contains($itemKey, ':')) {
            return [null, null];
        }

        [$type, $id] = explode(':', $itemKey, 2);

        if (! is_numeric($id)) {
            return [null, null];
        }

        return [$type, (int) $id];
    }

    /**
     * @return list<string>
     */
    protected function appliedFilterLabels(string $type, mixed $itemKey, string $search, ?string $statusFilter): array
    {
        $labels = [
            'Inventory Type: '.(self::inventoryTypeOptions()[$type] ?? 'All'),
        ];

        [$itemType, $itemId] = self::parseItemKey($itemKey);
        if ($itemType !== null && $itemId !== null) {
            $labels[] = 'Item: '.(self::itemLabel($itemType, $itemId) ?? ($itemType.' #'.$itemId));
        }

        if ($search !== '') {
            $labels[] = 'Search: '.$search;
        }

        if ($statusFilter === 'low_stock') {
            $labels[] = 'Stock Status: Low Stock';
        } elseif ($statusFilter === 'out_of_stock') {
            $labels[] = 'Stock Status: Out of Stock';
        } elseif (in_array($statusFilter, ['in_stock', 'available'], true)) {
            $labels[] = 'Stock Status: In Stock';
        }

        return $labels;
    }

    protected static function itemLabel(string $itemType, int $itemId): ?string
    {
        return match ($itemType) {
            self::TYPE_RAW_MATERIAL => RawMaterial::query()->whereKey($itemId)->value('material_name'),
            self::TYPE_PACKAGING_MATERIAL => PackagingMaterial::query()->whereKey($itemId)->value('packaging_name'),
            self::TYPE_SEMI_FINISHED => SemiFinishedMaterial::query()->whereKey($itemId)->value('material_name'),
            self::TYPE_FINISHED_PRODUCT => Product::query()->whereKey($itemId)->value('product_name'),
            default => null,
        };
    }

    protected function lowStockCount(string $inventoryType = self::TYPE_ALL): int
    {
        $raw = 0;
        $pack = 0;
        $semi = 0;
        $finished = 0;

        if ($inventoryType === self::TYPE_ALL || $inventoryType === self::TYPE_RAW_MATERIAL) {
            $raw = RawMaterial::query()
                ->where('status', true)
                ->whereColumn('current_stock', '<=', 'minimum_stock')
                ->where('current_stock', '>', 0)
                ->count();
        }

        if ($inventoryType === self::TYPE_ALL || $inventoryType === self::TYPE_PACKAGING_MATERIAL) {
            $pack = PackagingMaterial::query()
                ->where('status', true)
                ->whereColumn('current_stock', '<=', 'minimum_stock')
                ->where('current_stock', '>', 0)
                ->count();
        }

        if ($inventoryType === self::TYPE_ALL || $inventoryType === self::TYPE_SEMI_FINISHED) {
            $semi = SemiFinishedMaterial::query()
                ->where('status', true)
                ->whereColumn('current_stock', '<=', 'minimum_stock')
                ->where('current_stock', '>', 0)
                ->count();
        }

        if ($inventoryType === self::TYPE_ALL || $inventoryType === self::TYPE_FINISHED_PRODUCT) {
            $finished = Product::query()
                ->where('status', true)
                ->inFinishedInventory()
                ->whereColumn('current_finished_stock', '<=', 'minimum_finished_stock')
                ->where('current_finished_stock', '>', 0)
                ->count();
        }

        return $raw + $pack + $semi + $finished;
    }

    protected function outOfStockCount(string $inventoryType = self::TYPE_ALL): int
    {
        $raw = 0;
        $pack = 0;
        $semi = 0;
        $finished = 0;

        if ($inventoryType === self::TYPE_ALL || $inventoryType === self::TYPE_RAW_MATERIAL) {
            $raw = RawMaterial::query()->where('status', true)->where('current_stock', '<=', 0)->count();
        }

        if ($inventoryType === self::TYPE_ALL || $inventoryType === self::TYPE_PACKAGING_MATERIAL) {
            $pack = PackagingMaterial::query()->where('status', true)->where('current_stock', '<=', 0)->count();
        }

        if ($inventoryType === self::TYPE_ALL || $inventoryType === self::TYPE_SEMI_FINISHED) {
            $semi = SemiFinishedMaterial::query()->where('status', true)->where('current_stock', '<=', 0)->count();
        }

        if ($inventoryType === self::TYPE_ALL || $inventoryType === self::TYPE_FINISHED_PRODUCT) {
            $finished = Product::query()
                ->where('status', true)
                ->where('manufacturing_enabled', true)
                ->where('current_finished_stock', '<=', 0)
                ->count();
        }

        return $raw + $pack + $semi + $finished;
    }

    protected function stockStatusKey(float $qty, float $min): string
    {
        if ($qty <= 0) {
            return 'out_of_stock';
        }

        if ($qty <= $min) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    protected function formatMoney(float $amount): string
    {
        return '₹'.number_format($amount, 2);
    }
}
