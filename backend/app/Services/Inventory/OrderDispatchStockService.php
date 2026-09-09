<?php

namespace App\Services\Inventory;

use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Models\Order;
use App\Models\Product;
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
            $locked->loadMissing(['items.product:id,nos_per_case,product_name,weighted_average_cost']);

            if (! $this->shouldPost($locked)) {
                return;
            }

            $actor ??= $this->actorFor($locked);
            $qtyByProduct = $this->quantitiesByProduct($locked);

            foreach ($this->sortedProductIds($qtyByProduct) as $productId) {
                $qty = $qtyByProduct[$productId];
                $this->postProductOutward($locked, $productId, $qty, $actor);
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
            $locked->loadMissing(['items.product:id,nos_per_case,weighted_average_cost']);

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
     * Read-only audit of dispatched orders missing FG outward ledgers.
     * Pre-opening dispatches (dispatch date < opening as-on date) are ignored.
     * Same-day and later dispatches are included; opening is the start-of-day balance.
     *
     * @param  list<string>  $productNames
     * @return array{
     *     scanned_orders: int,
     *     already_posted_lines: int,
     *     missing_lines: int,
     *     ignored_pre_opening_lines: int,
     *     blocked_lines: int,
     *     zero_qty_lines: int,
     *     missing: list<array<string, mixed>>,
     *     ignored: list<array<string, mixed>>,
     *     product_effects: list<array<string, mixed>>
     * }
     */
    public function auditMissing(?int $orderId = null, array $productNames = []): array
    {
        $orders = $this->eligibleDispatchedOrders($orderId);
        $missing = [];
        $ignored = [];
        $alreadyPosted = 0;
        $zeroQty = 0;
        $simulated = [];
        $openings = [];

        foreach ($orders as $order) {
            $order->loadMissing(['items.product:id,product_name,product_code,nos_per_case,current_finished_stock']);
            $qtyByProduct = $this->quantitiesByProduct($order);
            $dispatchDate = $this->transactionDate($order);

            $seen = [];
            foreach ($order->items as $item) {
                $productId = (int) $item->product_id;
                if ($productId < 1 || isset($seen[$productId])) {
                    continue;
                }
                $seen[$productId] = true;

                $product = $item->product;
                if (! $this->productNameMatches($product?->product_name, $productNames)) {
                    continue;
                }

                $qty = $qtyByProduct[$productId] ?? 0.0;
                if ($qty <= 0) {
                    $zeroQty++;

                    continue;
                }

                if (! array_key_exists($productId, $openings)) {
                    $openings[$productId] = $this->openingStockSnapshot($productId);
                }
                $opening = $openings[$productId];

                if (! array_key_exists($productId, $simulated)) {
                    $stockNow = $this->currentFinishedStock($productId, $product);
                    $postOpeningNet = $opening['date'] === null
                        ? $stockNow
                        : $this->postOpeningLedgerNet($productId, $opening['date']);
                    $simulated[$productId] = [
                        'product_id' => $productId,
                        'product_code' => (string) ($product?->product_code ?? ''),
                        'product_name' => (string) ($product?->product_name ?? 'Product #'.$productId),
                        'opening_date' => $opening['date'],
                        'opening_qty' => $opening['qty'],
                        'stock_now' => $stockNow,
                        'ignore_before_qty' => 0.0,
                        'on_opening_date_qty' => 0.0,
                        'after_opening_qty' => 0.0,
                        'missing_outward' => 0.0,
                        'post_opening_ledger_net' => $postOpeningNet,
                    ];
                }

                $bucket = $this->dispatchOpeningBucket($dispatchDate, $opening['date']);
                if ($bucket === 'before') {
                    $simulated[$productId]['ignore_before_qty'] = round(
                        (float) $simulated[$productId]['ignore_before_qty'] + $qty,
                        3,
                    );
                    $ignored[] = [
                        'order_id' => (int) $order->id,
                        'order_no' => (string) $order->order_no,
                        'dispatch_date' => $dispatchDate,
                        'product_id' => $productId,
                        'product_name' => $simulated[$productId]['product_name'],
                        'qty_nos' => $qty,
                        'reason' => 'Dispatch date is before opening stock as-on date '.$opening['date'].'.',
                    ];

                    continue;
                }

                if ($this->hasLedger((int) $order->id, $productId, StockTransactionType::Dispatch, true)) {
                    $alreadyPosted++;

                    continue;
                }

                if ($bucket === 'on') {
                    $simulated[$productId]['on_opening_date_qty'] = round(
                        (float) $simulated[$productId]['on_opening_date_qty'] + $qty,
                        3,
                    );
                } else {
                    $simulated[$productId]['after_opening_qty'] = round(
                        (float) $simulated[$productId]['after_opening_qty'] + $qty,
                        3,
                    );
                }

                $stockBefore = round(
                    (float) $simulated[$productId]['opening_qty']
                    + (float) $simulated[$productId]['post_opening_ledger_net']
                    - (float) $simulated[$productId]['missing_outward'],
                    3,
                );
                $blocked = $stockBefore + 0.0001 < $qty;
                $simulated[$productId]['missing_outward'] = round(
                    (float) $simulated[$productId]['missing_outward'] + $qty,
                    3,
                );

                $missing[] = [
                    'order_id' => (int) $order->id,
                    'order_no' => (string) $order->order_no,
                    'dispatch_date' => $dispatchDate,
                    'product_id' => $productId,
                    'product_code' => $simulated[$productId]['product_code'],
                    'product_name' => $simulated[$productId]['product_name'],
                    'opening_date' => $opening['date'],
                    'period' => $bucket,
                    'qty_nos' => $qty,
                    'stock_before' => $stockBefore,
                    'expected_stock_after' => round($stockBefore - $qty, 3),
                    'blocked' => $blocked,
                    'block_reason' => $blocked
                        ? 'Insufficient finished stock ('.$stockBefore.' available, '.$qty.' required).'
                        : null,
                ];
            }
        }

        $productEffects = [];
        foreach ($simulated as $row) {
            if (
                (float) $row['missing_outward'] <= 0
                && (float) $row['ignore_before_qty'] <= 0
            ) {
                continue;
            }

            $productEffects[] = [
                'product_id' => $row['product_id'],
                'product_code' => $row['product_code'],
                'product_name' => $row['product_name'],
                'opening_date' => $row['opening_date'],
                'opening_qty' => $row['opening_qty'],
                'ignore_before_qty' => $row['ignore_before_qty'],
                'on_opening_date_qty' => $row['on_opening_date_qty'],
                'after_opening_qty' => $row['after_opening_qty'],
                'missing_outward' => $row['missing_outward'],
                'stock_now' => $row['stock_now'],
                'expected_closing' => round(
                    (float) $row['opening_qty']
                    + (float) $row['post_opening_ledger_net']
                    - (float) $row['missing_outward'],
                    3,
                ),
            ];
        }

        $blocked = count(array_filter($missing, fn (array $row): bool => (bool) $row['blocked']));

        return [
            'scanned_orders' => $orders->count(),
            'already_posted_lines' => $alreadyPosted,
            'missing_lines' => count($missing),
            'ignored_pre_opening_lines' => count($ignored),
            'blocked_lines' => $blocked,
            'zero_qty_lines' => $zeroQty,
            'missing' => $missing,
            'ignored' => $ignored,
            'product_effects' => $productEffects,
        ];
    }

    /**
     * Post missing dispatch stock for already-dispatched orders that have no FG ledger.
     * Never posts a second time when a dispatch ledger already exists.
     *
     * @return array{posted: int, skipped: int, failed: int, failed_orders: list<array{order_id: int, order_no: string, error: string}>}
     */
    public function postMissingForDispatchedOrders(?int $orderId = null, array $productNames = []): array
    {
        $audit = $this->auditMissing($orderId, $productNames);
        $posted = 0;
        $skipped = $audit['already_posted_lines'];
        $failed = 0;
        $failedOrders = [];

        foreach ($audit['missing'] as $row) {
            if ($row['blocked']) {
                $failed++;
                $failedOrders[] = [
                    'order_id' => (int) $row['order_id'],
                    'order_no' => (string) $row['order_no'],
                    'error' => (string) $row['block_reason'],
                ];

                continue;
            }

            $order = Order::query()->with('items.product')->find((int) $row['order_id']);
            if ($order === null) {
                $skipped++;

                continue;
            }

            try {
                $before = $this->hasLedger(
                    (int) $row['order_id'],
                    (int) $row['product_id'],
                    StockTransactionType::Dispatch,
                    true,
                );
                $this->postMissingProductLine($order, (int) $row['product_id']);
                $after = $this->hasLedger(
                    (int) $row['order_id'],
                    (int) $row['product_id'],
                    StockTransactionType::Dispatch,
                    true,
                );
                if ($after && ! $before) {
                    $posted++;
                } else {
                    $skipped++;
                }
            } catch (ValidationException $e) {
                $failed++;
                $failedOrders[] = [
                    'order_id' => (int) $order->id,
                    'order_no' => (string) $order->order_no,
                    'error' => collect($e->errors())->flatten()->implode(' '),
                ];
            }
        }

        return [
            'posted' => $posted,
            'skipped' => $skipped,
            'failed' => $failed,
            'failed_orders' => $failedOrders,
        ];
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

            $qty = $item->quantityInNos();
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

    private function postProductOutward(Order $order, int $productId, float $qty, ?User $actor): void
    {
        if ($this->hasLedger($order->id, $productId, StockTransactionType::Dispatch, true)) {
            return;
        }

        $product = $this->inventoryService->lockProduct($productId);
        $rate = (float) $product->weighted_average_cost;

        $this->ledgerService->postFinishedProductMovement(
            $product,
            0,
            $qty,
            $rate,
            [
                'transaction_date' => $this->transactionDate($order),
                'transaction_type' => StockTransactionType::Dispatch,
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'reference_number' => $order->order_no,
                'remarks' => 'Sales order dispatch '.$order->order_no,
            ],
            $actor,
        );
    }

    private function postMissingProductLine(Order $order, int $productId): void
    {
        DB::transaction(function () use ($order, $productId): void {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['items.product:id,nos_per_case,weighted_average_cost']);

            if (! $this->shouldPost($locked)) {
                return;
            }

            $qty = $this->quantitiesByProduct($locked)[$productId] ?? 0.0;
            if ($qty <= 0) {
                return;
            }

            $this->postProductOutward($locked, $productId, $qty, $this->actorFor($locked));
        });
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

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Order>
     */
    private function eligibleDispatchedOrders(?int $orderId = null)
    {
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
            ->orderByRaw('COALESCE(dispatch_date, dispatched_at, order_date) asc')
            ->orderBy('id')
            ->with(['items.product:id,product_name,product_code,nos_per_case,current_finished_stock,weighted_average_cost']);

        if ($orderId !== null) {
            $query->whereKey($orderId);
        }

        return $query->get();
    }

    /**
     * @return array{date: ?string, qty: float}
     */
    private function openingStockSnapshot(int $productId): array
    {
        $ledger = StockLedger::query()
            ->where('item_type', StockItemType::FinishedProduct)
            ->where('product_id', $productId)
            ->where('transaction_type', StockTransactionType::OpeningStock)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->first();

        if ($ledger === null) {
            return ['date' => null, 'qty' => 0.0];
        }

        return [
            'date' => $ledger->transaction_date?->toDateString(),
            'qty' => round((float) $ledger->quantity_in, 3),
        ];
    }

    /**
     * Ledger qty after the opening row on the as-on date (opening is start-of-day).
     * Includes same-day non-opening movements and all later ledgers.
     */
    private function postOpeningLedgerNet(int $productId, ?string $openingDate): float
    {
        if ($openingDate === null) {
            return $this->currentFinishedStock($productId, null);
        }

        $net = 0.0;
        $ledgers = StockLedger::query()
            ->where('item_type', StockItemType::FinishedProduct)
            ->where('product_id', $productId)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($ledgers as $ledger) {
            $date = $ledger->transaction_date?->toDateString();
            if ($date === null || $date < $openingDate) {
                continue;
            }

            if ($date === $openingDate && $ledger->transaction_type === StockTransactionType::OpeningStock) {
                continue;
            }

            $net = round(
                $net + (float) $ledger->quantity_in - (float) $ledger->quantity_out,
                3,
            );
        }

        return $net;
    }

    /**
     * @return 'before'|'on'|'after'
     */
    private function dispatchOpeningBucket(string $dispatchDate, ?string $openingDate): string
    {
        if ($openingDate === null || $openingDate === '') {
            return 'after';
        }

        if ($dispatchDate < $openingDate) {
            return 'before';
        }

        return $dispatchDate === $openingDate ? 'on' : 'after';
    }

    /**
     * @param  list<string>  $productNames
     */
    private function productNameMatches(?string $productName, array $productNames): bool
    {
        $needles = [];
        foreach ($productNames as $name) {
            foreach (preg_split('/\s*,\s*/', trim((string) $name)) ?: [] as $part) {
                if ($part !== '') {
                    $needles[] = $this->normalizeProductName($part);
                }
            }
        }

        if ($needles === []) {
            return true;
        }

        $haystack = $this->normalizeProductName((string) $productName);
        foreach ($needles as $needle) {
            if ($haystack === $needle || str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeProductName(string $name): string
    {
        return strtoupper(preg_replace('/\s+/', ' ', trim($name)) ?? '');
    }

    private function currentFinishedStock(int $productId, ?Product $product): float
    {
        $latest = StockLedger::query()
            ->where('item_type', StockItemType::FinishedProduct)
            ->where('product_id', $productId)
            ->orderByDesc('id')
            ->value('stock_after');

        if ($latest !== null) {
            return max(0.0, round((float) $latest, 3));
        }

        $product ??= Product::query()->find($productId);

        return max(0.0, round((float) ($product?->current_finished_stock ?? 0), 3));
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
