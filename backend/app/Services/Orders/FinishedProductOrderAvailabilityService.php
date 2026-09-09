<?php

namespace App\Services\Orders;

use App\Enums\StockItemType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * Live finished-product availability for open sales orders.
 *
 * Virtual FIFO only: does not persist a reservation, deduct stock, or write
 * ledger rows. Actual stock is the current physical finished balance from the
 * stock ledger (latest stock_after), falling back to products.current_finished_stock
 * when no ledger exists.
 *
 * For each product, every active order is processed independently in date/id
 * order. Earlier reserved qty is the FULL required qty of earlier active
 * orders for that product_id — not the qty those orders were able to fill.
 *
 * Dispatched orders are excluded because dispatch is already posted in the ledger.
 */
final class FinishedProductOrderAvailabilityService
{
    /**
     * Statuses that still require finished stock and may hold a virtual allocation.
     *
     * @return list<string>
     */
    public static function openStatuses(): array
    {
        return [
            Order::STATUS_PENDING_APPROVAL,
            Order::STATUS_APPROVED,
            Order::STATUS_ON_HOLD,
            Order::STATUS_PENDING_FOR_BILLING,
            Order::STATUS_BILLED,
        ];
    }

    /**
     * Completed / non-active statuses that must never reserve finished stock.
     *
     * @return list<string>
     */
    public static function releasedStatuses(): array
    {
        return [
            Order::STATUS_DISPATCHED,
            Order::STATUS_REJECTED,
            Order::STATUS_REVERTED_TO_MANAGER,
            'cancelled',
            'delivered',
            'draft',
        ];
    }

    public static function normalizeStatus(mixed $status): string
    {
        return strtolower(trim((string) $status));
    }

    /**
     * Whether this order currently waits for dispatch and may reserve stock.
     * Uses live status plus completion timestamps so stale statuses do not hold stock.
     */
    public static function isEligibleForReservation(Order $order): bool
    {
        $status = self::normalizeStatus($order->status);

        if ($status === '' || ! in_array($status, self::openStatuses(), true)) {
            return false;
        }

        if (in_array($status, self::releasedStatuses(), true)) {
            return false;
        }

        if (filled($order->dispatched_at)) {
            return false;
        }

        if (filled($order->rejected_at)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function attachToPayload(array $payload, Order $order): array
    {
        $order->loadMissing(['items']);
        $productIds = $order->items
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $applies = self::isEligibleForReservation($order) && $productIds !== [];
        $byProduct = $applies
            ? ($this->allocate($productIds)[(int) $order->id] ?? [])
            : [];

        $rows = array_values($byProduct);
        $summary = $this->summarizeRows($rows, $applies);

        $payload['stock_availability_applies'] = $applies;
        $payload['stock_availability'] = $rows;
        $payload['stock_status'] = $summary['stock_status'];
        $payload['stock_status_label'] = $summary['stock_status_label'];
        $payload['has_stock_shortage'] = $summary['has_stock_shortage'];
        $payload['stock_short_label'] = $summary['stock_short_label'];

        foreach (['items', 'line_items'] as $key) {
            if (! isset($payload[$key]) || ! is_array($payload[$key])) {
                continue;
            }

            $payload[$key] = array_map(function (mixed $row) use ($byProduct): mixed {
                if (! is_array($row)) {
                    return $row;
                }

                $productId = (int) ($row['product_id'] ?? 0);
                if ($productId > 0 && isset($byProduct[$productId])) {
                    $row['stock_availability'] = $byProduct[$productId];
                }

                return $row;
            }, $payload[$key]);
        }

        return $payload;
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, array{
     *     stock_availability_applies: bool,
     *     stock_status: ?string,
     *     stock_status_label: ?string,
     *     has_stock_shortage: bool,
     *     stock_short_label: ?string
     * }>
     */
    public function summariesForOrderIds(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        $blank = [
            'stock_availability_applies' => false,
            'stock_status' => null,
            'stock_status_label' => null,
            'has_stock_shortage' => false,
            'stock_short_label' => null,
        ];

        if ($orderIds === []) {
            return [];
        }

        $productIds = OrderItem::query()
            ->whereIn('order_id', $orderIds)
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $allocated = $productIds === [] ? [] : $this->allocate($productIds);
        $summaries = [];

        foreach ($orderIds as $orderId) {
            $rows = array_values($allocated[$orderId] ?? []);
            $summaries[$orderId] = $this->summarizeRows($rows, $rows !== []);
            if ($rows === []) {
                $summaries[$orderId] = $blank;
            }
        }

        return $summaries;
    }

    /**
     * @param  list<int>|null  $productIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function allocate(?array $productIds = null): array
    {
        $productIds = $productIds === null
            ? null
            : array_values(array_unique(array_filter(array_map('intval', $productIds))));

        $openOrders = Order::query()
            ->whereIn('status', self::openStatuses())
            ->whereNotIn('status', self::releasedStatuses())
            ->whereNull('dispatched_at')
            ->whereNull('rejected_at')
            ->orderBy('order_date')
            ->orderBy('id')
            ->with(['items' => function ($query) use ($productIds): void {
                $query->select([
                    'id',
                    'order_id',
                    'product_id',
                    'total_quantity_nos',
                    'case_quantity',
                    'nos_per_case',
                    'quantity',
                ]);
                if ($productIds !== null) {
                    $query->whereIn('product_id', $productIds);
                }
            }])
            ->get(['id', 'order_date', 'order_no', 'status', 'dispatched_at', 'rejected_at']);

        $neededProductIds = [];
        $orderProductQty = [];

        foreach ($openOrders as $openOrder) {
            if (! self::isEligibleForReservation($openOrder)) {
                continue;
            }

            foreach ($openOrder->items as $item) {
                $productId = (int) $item->product_id;
                if ($productId < 1) {
                    continue;
                }

                $qty = $this->lineQuantityNos($item);
                if ($qty <= 0) {
                    continue;
                }

                $neededProductIds[$productId] = true;
                $orderProductQty[(int) $openOrder->id][$productId] = round(
                    (float) ($orderProductQty[(int) $openOrder->id][$productId] ?? 0) + $qty,
                    3,
                );
            }
        }

        $ids = array_keys($neededProductIds);
        if ($ids === []) {
            return [];
        }

        $products = Product::query()
            ->whereIn('id', $ids)
            ->get(['id', 'product_name', 'product_code', 'uom', 'production_unit', 'current_finished_stock'])
            ->keyBy('id');

        $physicalStock = $this->physicalStockByProductIds($ids, $products);
        $requiredByEarlier = [];
        $result = [];

        foreach ($openOrders as $openOrder) {
            if (! self::isEligibleForReservation($openOrder)) {
                continue;
            }

            $orderId = (int) $openOrder->id;
            foreach ($orderProductQty[$orderId] ?? [] as $productId => $orderQty) {
                $product = $products->get($productId);
                $currentStock = $physicalStock[$productId] ?? 0.0;
                $earlierReserved = round((float) ($requiredByEarlier[$productId] ?? 0.0), 3);
                $remainingBefore = round(max(0.0, $currentStock - $earlierReserved), 3);
                $allocatedToThis = round(min($orderQty, $remainingBefore), 3);
                $shortQty = round(max(0.0, $orderQty - $remainingBefore), 3);
                $requiredByEarlier[$productId] = round($earlierReserved + $orderQty, 3);

                $unit = trim((string) ($product?->production_unit ?: $product?->uom ?: 'Nos'));
                if ($unit === '') {
                    $unit = 'Nos';
                }

                $status = $shortQty > 0.0001 ? 'short' : 'available';

                $result[$orderId][$productId] = [
                    'product_id' => $productId,
                    'product_name' => (string) ($product?->product_name ?? ''),
                    'product_code' => (string) ($product?->product_code ?? ''),
                    'unit' => $unit,
                    'order_qty' => $orderQty,
                    'current_finished_stock' => $currentStock,
                    'allocated_to_earlier_orders' => $earlierReserved,
                    'remaining_before_this_order' => $remainingBefore,
                    'allocated_to_this_order' => $allocatedToThis,
                    'available_for_this_order' => $remainingBefore,
                    'short_qty' => $shortQty,
                    'stock_status' => $status,
                    'stock_status_label' => $this->statusLabel($status),
                    'short_label' => $shortQty > 0.0001
                        ? 'Short '.$this->formatQty($shortQty).' '.$unit
                        : null,
                ];
            }
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     stock_availability_applies: bool,
     *     stock_status: ?string,
     *     stock_status_label: ?string,
     *     has_stock_shortage: bool,
     *     stock_short_label: ?string
     * }
     */
    private function summarizeRows(array $rows, bool $applies): array
    {
        if (! $applies || $rows === []) {
            return [
                'stock_availability_applies' => false,
                'stock_status' => null,
                'stock_status_label' => null,
                'has_stock_shortage' => false,
                'stock_short_label' => null,
            ];
        }

        $hasShort = false;
        $shortLabels = [];

        foreach ($rows as $row) {
            $short = (float) ($row['short_qty'] ?? 0);
            if ($short > 0.0001) {
                $hasShort = true;
                if (filled($row['short_label'] ?? null)) {
                    $shortLabels[] = (string) $row['short_label'];
                }
            }
        }

        $status = $hasShort ? 'short' : 'available';
        $shortLabel = null;
        if ($hasShort) {
            $shortLabel = count($shortLabels) === 1
                ? $shortLabels[0]
                : 'Shortage on '.count($shortLabels).' products';
        }

        return [
            'stock_availability_applies' => true,
            'stock_status' => $status,
            'stock_status_label' => $this->statusLabel($status),
            'has_stock_shortage' => $hasShort,
            'stock_short_label' => $shortLabel,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'available' => 'Available',
            'short' => 'Short',
            default => 'Available',
        };
    }

    private function formatQty(float $qty): string
    {
        if (abs($qty - round($qty)) < 0.0001) {
            return (string) (int) round($qty);
        }

        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    }

    /**
     * Order qty in the same Nos base unit as finished stock.
     * Prefer stored total_quantity_nos; otherwise Cases × Qty Per Case.
     */
    private function lineQuantityNos(OrderItem $item): float
    {
        return $item->quantityInNos();
    }

    /**
     * @param  list<int>  $productIds
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return array<int, float>
     */
    private function physicalStockByProductIds(array $productIds, $products): array
    {
        $stock = [];
        foreach ($productIds as $productId) {
            $stock[$productId] = round((float) ($products->get($productId)?->current_finished_stock ?? 0), 3);
        }

        if ($productIds === []) {
            return $stock;
        }

        $latestIds = StockLedger::query()
            ->select('product_id', DB::raw('MAX(id) as latest_id'))
            ->where('item_type', StockItemType::FinishedProduct)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id');

        $rows = StockLedger::query()
            ->joinSub($latestIds, 'latest_finished_stock', function ($join): void {
                $join->on('stock_ledgers.id', '=', 'latest_finished_stock.latest_id');
            })
            ->get([
                'stock_ledgers.product_id',
                'stock_ledgers.stock_after',
            ]);

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            if ($productId < 1) {
                continue;
            }

            $stock[$productId] = round((float) $row->stock_after, 3);
        }

        return $stock;
    }
}
