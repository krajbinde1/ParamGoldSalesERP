<?php

namespace App\Services\Inventory;

use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Models\Order;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts finished-product outward stock when a sales order is dispatched.
 * Idempotent per order + product. Does not persist a reservation before dispatch.
 */
final class OrderDispatchStockService
{
    public function __construct(
        private readonly StockLedgerService $ledgerService = new StockLedgerService,
        private readonly InventoryService $inventoryService = new InventoryService,
    ) {}

    /**
     * Deduct dispatched line quantities from finished stock exactly once.
     * Rejected and cancelled orders are ignored.
     */
    public function postForDispatchedOrder(Order $order, ?User $actor = null): void
    {
        DB::transaction(function () use ($order, $actor): void {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['items']);

            if (! $this->shouldPost($locked)) {
                return;
            }

            $actor ??= $this->actorFor($locked);
            $qtyByProduct = $this->quantitiesByProduct($locked);

            foreach ($this->sortedProductIds($qtyByProduct) as $productId) {
                $qty = $qtyByProduct[$productId];
                if ($this->hasLedger($locked->id, $productId, StockTransactionType::Dispatch, true)) {
                    continue;
                }

                $product = $this->inventoryService->lockProduct($productId);
                $rate = (float) $product->weighted_average_cost;

                $this->ledgerService->postFinishedProductMovement(
                    $product,
                    0,
                    $qty,
                    $rate,
                    [
                        'transaction_date' => $this->transactionDate($locked),
                        'transaction_type' => StockTransactionType::Dispatch,
                        'reference_type' => Order::class,
                        'reference_id' => $locked->id,
                        'reference_number' => $locked->order_no,
                        'remarks' => 'Sales order dispatch '.$locked->order_no,
                    ],
                    $actor,
                );
            }
        });
    }

    /**
     * Restore finished stock if a dispatched order is later rejected.
     * No-op when no dispatch ledger exists (historical orders that never deducted).
     */
    public function reverseForRejectedOrder(Order $order, ?User $actor = null): void
    {
        DB::transaction(function () use ($order, $actor): void {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['items']);

            $status = strtolower(trim((string) $locked->status));
            if ($status !== Order::STATUS_REJECTED && $status !== 'cancelled') {
                return;
            }

            $actor ??= $this->actorFor($locked);
            $qtyByProduct = $this->quantitiesByProduct($locked);

            foreach ($this->sortedProductIds($qtyByProduct) as $productId) {
                $qty = $qtyByProduct[$productId];
                if (! $this->hasLedger($locked->id, $productId, StockTransactionType::Dispatch, true)) {
                    continue;
                }
                if ($this->hasLedger($locked->id, $productId, StockTransactionType::Return, false)) {
                    continue;
                }

                $product = $this->inventoryService->lockProduct($productId);
                $rate = (float) $product->weighted_average_cost;

                $this->ledgerService->postFinishedProductMovement(
                    $product,
                    $qty,
                    0,
                    $rate,
                    [
                        'transaction_date' => Carbon::now('Asia/Kolkata')->toDateString(),
                        'transaction_type' => StockTransactionType::Return,
                        'reference_type' => Order::class,
                        'reference_id' => $locked->id,
                        'reference_number' => $locked->order_no,
                        'remarks' => 'Sales order rejection restore '.$locked->order_no,
                    ],
                    $actor,
                );
            }
        });
    }

    /**
     * Post missing dispatch stock for already-dispatched orders that have no FG ledger.
     * Never posts a second time when a dispatch ledger already exists.
     *
     * @return array{posted: int, skipped: int}
     */
    public function postMissingForDispatchedOrders(?int $orderId = null): array
    {
        $posted = 0;
        $skipped = 0;

        $query = Order::query()
            ->where(function ($inner): void {
                $inner->where('status', Order::STATUS_DISPATCHED)
                    ->orWhereNotNull('dispatched_at');
            })
            ->whereNotIn('status', [
                Order::STATUS_REJECTED,
                'cancelled',
                'draft',
            ])
            ->orderBy('dispatched_at')
            ->orderBy('id')
            ->with('items');

        if ($orderId !== null) {
            $query->whereKey($orderId);
        }

        foreach ($query->get() as $order) {
            $before = $this->dispatchLedgerCount((int) $order->id);
            try {
                $this->postForDispatchedOrder($order);
            } catch (ValidationException) {
                $skipped++;

                continue;
            }
            $after = $this->dispatchLedgerCount((int) $order->id);
            if ($after > $before) {
                $posted++;
            } else {
                $skipped++;
            }
        }

        return ['posted' => $posted, 'skipped' => $skipped];
    }

    private function shouldPost(Order $order): bool
    {
        $status = strtolower(trim((string) $order->status));

        if (in_array($status, [Order::STATUS_REJECTED, 'cancelled', 'draft'], true)) {
            return false;
        }

        return $status === Order::STATUS_DISPATCHED || filled($order->dispatched_at);
    }

    /**
     * @return array<int, float>
     */
    private function quantitiesByProduct(Order $order): array
    {
        $qtyByProduct = [];

        foreach ($order->items as $item) {
            $productId = (int) $item->product_id;
            if ($productId < 1) {
                continue;
            }

            $qty = round((float) ($item->total_quantity_nos ?? $item->quantity ?? 0), 3);
            if ($qty <= 0) {
                continue;
            }

            $qtyByProduct[$productId] = round(($qtyByProduct[$productId] ?? 0) + $qty, 3);
        }

        return $qtyByProduct;
    }

    /**
     * @param  array<int, float>  $qtyByProduct
     * @return list<int>
     */
    private function sortedProductIds(array $qtyByProduct): array
    {
        $ids = array_map('intval', array_keys($qtyByProduct));
        sort($ids);

        return $ids;
    }

    private function hasLedger(int $orderId, int $productId, StockTransactionType $type, bool $outward): bool
    {
        return StockLedger::query()
            ->where('item_type', StockItemType::FinishedProduct)
            ->where('transaction_type', $type)
            ->where('reference_type', Order::class)
            ->where('reference_id', $orderId)
            ->where('product_id', $productId)
            ->when(
                $outward,
                fn ($query) => $query->where('quantity_out', '>', 0),
                fn ($query) => $query->where('quantity_in', '>', 0),
            )
            ->exists();
    }

    private function dispatchLedgerCount(int $orderId): int
    {
        return StockLedger::query()
            ->where('item_type', StockItemType::FinishedProduct)
            ->where('transaction_type', StockTransactionType::Dispatch)
            ->where('reference_type', Order::class)
            ->where('reference_id', $orderId)
            ->where('quantity_out', '>', 0)
            ->count();
    }

    private function transactionDate(Order $order): string
    {
        return $order->dispatch_date?->toDateString()
            ?? $order->dispatched_at?->timezone('Asia/Kolkata')?->toDateString()
            ?? Carbon::now('Asia/Kolkata')->toDateString();
    }

    private function actorFor(Order $order): ?User
    {
        if (filled($order->dispatched_by)) {
            return User::query()->find($order->dispatched_by);
        }

        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
