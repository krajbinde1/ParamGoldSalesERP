<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

/**
 * Live finished-product availability for open sales orders.
 *
 * Allocates current_finished_stock virtually, oldest order first
 * (order_date, then order_no). Does not persist a reservation or deduct stock.
 *
 * Only orders that still require stock consume the virtual pool. Dispatched,
 * rejected, cancelled, and reverted (returned to manager) orders release
 * their allocation immediately on the next calculation — including existing
 * orders, because nothing is stored.
 */
final class FinishedProductOrderAvailabilityService
{
    /**
     * Statuses that still require finished stock and may hold a virtual allocation.
     * Dispatched, rejected, cancelled, and reverted orders are excluded.
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

        $applies = in_array($order->status, self::openStatuses(), true) && $productIds !== [];
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
            ->orderBy('order_date')
            ->orderBy('order_no')
            ->orderBy('id')
            ->with(['items' => function ($query) use ($productIds): void {
                $query->select(['id', 'order_id', 'product_id', 'total_quantity_nos', 'quantity']);
                if ($productIds !== null) {
                    $query->whereIn('product_id', $productIds);
                }
            }])
            ->get(['id', 'order_date', 'order_no', 'status']);

        $neededProductIds = [];
        $orderProductQty = [];

        foreach ($openOrders as $openOrder) {
            foreach ($openOrder->items as $item) {
                $productId = (int) $item->product_id;
                if ($productId < 1) {
                    continue;
                }

                $qty = (float) ($item->total_quantity_nos ?? $item->quantity ?? 0);
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

        $allocatedToEarlier = [];
        $result = [];

        foreach ($openOrders as $openOrder) {
            $orderId = (int) $openOrder->id;
            foreach ($orderProductQty[$orderId] ?? [] as $productId => $orderQty) {
                $product = $products->get($productId);
                $currentStock = max(0.0, round((float) ($product?->current_finished_stock ?? 0), 3));
                $allocatedEarlier = round((float) ($allocatedToEarlier[$productId] ?? 0.0), 3);
                $left = round(max(0.0, $currentStock - $allocatedEarlier), 3);
                $availableForThis = round(min($orderQty, $left), 3);
                $shortQty = round(max(0.0, $orderQty - $availableForThis), 3);
                $allocatedToEarlier[$productId] = round($allocatedEarlier + $availableForThis, 3);

                $unit = trim((string) ($product?->production_unit ?: $product?->uom ?: 'Nos'));
                if ($unit === '') {
                    $unit = 'Nos';
                }

                $status = $this->lineStatus($orderQty, $availableForThis, $shortQty);

                $result[$orderId][$productId] = [
                    'product_id' => $productId,
                    'product_name' => (string) ($product?->product_name ?? ''),
                    'product_code' => (string) ($product?->product_code ?? ''),
                    'unit' => $unit,
                    'order_qty' => $orderQty,
                    'current_finished_stock' => $currentStock,
                    'allocated_to_earlier_orders' => $allocatedEarlier,
                    'available_for_this_order' => $availableForThis,
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
        $allAvailable = true;
        $allOut = true;
        $shortLabels = [];

        foreach ($rows as $row) {
            $status = (string) ($row['stock_status'] ?? '');
            if ($status !== 'available') {
                $allAvailable = false;
            }
            if ($status !== 'out_of_stock') {
                $allOut = false;
            }
            $short = (float) ($row['short_qty'] ?? 0);
            if ($short > 0.0001) {
                $hasShort = true;
                if (filled($row['short_label'] ?? null)) {
                    $shortLabels[] = (string) $row['short_label'];
                }
            }
        }

        $status = $allAvailable
            ? 'available'
            : ($allOut ? 'out_of_stock' : 'partial_stock');

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

    private function lineStatus(float $orderQty, float $available, float $short): string
    {
        if ($orderQty <= 0.0001 || $short <= 0.0001) {
            return 'available';
        }

        if ($available <= 0.0001) {
            return 'out_of_stock';
        }

        return 'partial_stock';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'available' => 'Available',
            'partial_stock' => 'Partial Stock',
            'out_of_stock' => 'Out of Stock',
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
}
