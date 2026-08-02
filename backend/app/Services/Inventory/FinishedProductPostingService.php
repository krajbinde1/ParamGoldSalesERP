<?php

namespace App\Services\Inventory;

use App\Enums\ProductionBatchStatus;
use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts finished-product stock for a production batch (idempotent).
 * Products module records are finished products — stock lives on Product
 * (current_finished_stock / weighted_average_cost).
 */
final class FinishedProductPostingService
{
    public function __construct(
        private readonly StockLedgerService $ledgerService = new StockLedgerService,
        private readonly InventoryService $inventoryService = new InventoryService,
    ) {}

    public function hasPosted(ProductionBatch $batch): bool
    {
        if ($batch->finished_product_posted_at !== null || $batch->finished_product_ledger_id !== null) {
            return true;
        }

        return $this->findExistingFinishedProductLedger($batch) !== null;
    }

    public function assertNotPosted(ProductionBatch $batch): void
    {
        if ($this->hasPosted($batch) || $batch->status === ProductionBatchStatus::Completed) {
            throw ValidationException::withMessages([
                'production' => 'This production batch has already been posted.',
            ]);
        }
    }

    /**
     * Add production quantity to finished product stock, update weighted average
     * production cost, create FG production ledger, and stamp the batch.
     * Caller must already hold a lock on $product and be inside a DB transaction.
     */
    public function postFromProduction(
        ProductionBatch $batch,
        Product $product,
        float $quantity,
        float $batchCost,
        float $costPerUnit,
        string $transactionDate,
        User $user,
    ): StockLedger {
        if ($this->hasPosted($batch)) {
            throw ValidationException::withMessages([
                'production' => 'This production batch has already been posted.',
            ]);
        }

        $quantity = round($quantity, 3);
        $batchCost = round($batchCost, 2);
        $costPerUnit = round($costPerUnit, 4);

        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'actual_output_quantity' => 'Production quantity must be greater than zero to post finished stock.',
            ]);
        }

        $oldStock = (float) $product->current_finished_stock;
        $oldAvg = (float) $product->weighted_average_cost;
        $oldValue = round(max(0, $oldStock) * max(0, $oldAvg), 2);

        $newStock = round($oldStock + $quantity, 3);
        $newValue = round($oldValue + $batchCost, 2);
        $newAvg = $newStock > 0.0001
            ? round($newValue / $newStock, 4)
            : 0.0;

        $particulars = 'Production Batch '.$batch->batch_number;

        $ledger = $this->ledgerService->postFinishedProductMovement(
            $product,
            $quantity,
            0,
            $costPerUnit,
            [
                'transaction_date' => $transactionDate,
                'transaction_type' => StockTransactionType::ProductionOutput,
                'reference_type' => ProductionBatch::class,
                'reference_id' => $batch->id,
                'reference_number' => $batch->batch_number,
                'batch_number' => $batch->batch_number,
                'remarks' => $particulars,
                'old_average_rate' => $oldAvg,
                'new_average_rate' => $newAvg,
                'inward_value' => $batchCost,
                'transaction_value' => $batchCost,
                'opening_value' => $oldValue,
                'closing_value' => $newValue,
            ],
            $user,
        );

        $product->refresh();
        $product->manufacturing_enabled = true;
        $product->latest_production_cost = $costPerUnit;
        $product->weighted_average_cost = $newAvg;
        $product->current_finished_stock = $newStock;
        $product->save();

        $batch->fill([
            'finished_product_posted_at' => now(),
            'finished_stock_before' => $oldStock,
            'finished_stock_after' => $newStock,
            'finished_stock_value_after' => $newValue,
            'finished_product_ledger_id' => $ledger->id,
        ]);
        $batch->save();

        return $ledger;
    }

    /**
     * Repair completed batches that deducted materials but never posted FG stock.
     * Does not re-consume raw/packaging materials.
     *
     * @return array{
     *     status: string,
     *     batch_number: string,
     *     message: string,
     *     quantity_added?: float,
     *     batch_cost?: float,
     *     stock_before?: float,
     *     stock_after?: float,
     *     ledger_id?: int|null
     * }
     */
    public function backfillMissing(ProductionBatch $batch, ?User $user = null): array
    {
        return DB::transaction(function () use ($batch, $user) {
            /** @var ProductionBatch $locked */
            $locked = ProductionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            $existing = $this->findExistingFinishedProductLedger($locked);
            if ($existing !== null || $locked->finished_product_posted_at !== null) {
                if ($locked->finished_product_posted_at === null && $existing !== null) {
                    $this->stampBatchFromLedger($locked, $existing);
                }

                return [
                    'status' => 'already_posted',
                    'batch_number' => $locked->batch_number,
                    'message' => 'This production batch has already been posted.',
                    'quantity_added' => (float) ($existing?->quantity_in ?? $locked->actual_output_quantity),
                    'batch_cost' => (float) ($existing?->transaction_value ?? $locked->total_batch_cost),
                    'stock_before' => (float) ($locked->finished_stock_before ?? $existing?->stock_before ?? 0),
                    'stock_after' => (float) ($locked->finished_stock_after ?? $existing?->stock_after ?? 0),
                    'ledger_id' => $existing?->id ?? $locked->finished_product_ledger_id,
                ];
            }

            if ($locked->status !== ProductionBatchStatus::Completed) {
                throw ValidationException::withMessages([
                    'status' => 'Only completed production batches can be backfilled for finished product stock.',
                ]);
            }

            $product = $this->inventoryService->lockProduct((int) $locked->product_id);
            $qty = round((float) $locked->actual_output_quantity, 3);
            $batchCost = round((float) $locked->total_batch_cost, 2);
            $costPerUnit = (float) $locked->cost_per_unit;
            if ($costPerUnit <= 0 && $qty > 0) {
                $costPerUnit = round($batchCost / $qty, 4);
            }

            $transactionDate = $locked->production_date?->toDateString()
                ?? $locked->completed_at?->toDateString()
                ?? now('Asia/Kolkata')->toDateString();

            $ledger = $this->postFromProduction(
                $locked,
                $product,
                $qty,
                $batchCost,
                $costPerUnit,
                $transactionDate,
                $user ?? $locked->supervisor ?? User::query()->findOrFail((int) $locked->supervisor_id),
            );

            return [
                'status' => 'posted',
                'batch_number' => $locked->batch_number,
                'message' => 'Finished product stock posted successfully.',
                'quantity_added' => $qty,
                'batch_cost' => $batchCost,
                'stock_before' => (float) $locked->fresh()->finished_stock_before,
                'stock_after' => (float) $locked->fresh()->finished_stock_after,
                'ledger_id' => $ledger->id,
            ];
        });
    }

    public function findExistingFinishedProductLedger(ProductionBatch $batch): ?StockLedger
    {
        if ($batch->finished_product_ledger_id) {
            $byId = StockLedger::query()->find($batch->finished_product_ledger_id);
            if ($byId !== null) {
                return $byId;
            }
        }

        return StockLedger::query()
            ->where('item_type', StockItemType::FinishedProduct)
            ->where('transaction_type', StockTransactionType::ProductionOutput)
            ->where('reference_type', ProductionBatch::class)
            ->where('reference_id', $batch->id)
            ->where('product_id', $batch->product_id)
            ->whereNull('raw_material_id')
            ->whereNull('packaging_material_id')
            ->orderBy('id')
            ->first();
    }

    /**
     * Repair completed batches whose finished output was wrongly mapped to RM/PM
     * (or never surfaced as FG because manufacturing_enabled stayed false).
     * Does not re-consume materials. Idempotent when a correct FG ledger already exists.
     *
     * @return array{
     *     status: string,
     *     batch_number: string,
     *     message: string,
     *     quantity_added?: float,
     *     batch_cost?: float,
     *     stock_before?: float,
     *     stock_after?: float,
     *     stock_value_after?: float,
     *     ledger_id?: int|null,
     *     removed_wrong_ledgers?: list<int>,
     *     rm_stock_adjusted?: list<array{raw_material_id: int, quantity_removed: float, stock_after: float}>
     * }
     */
    public function repairMispostedOutput(ProductionBatch $batch, ?User $user = null): array
    {
        return DB::transaction(function () use ($batch, $user) {
            /** @var ProductionBatch $locked */
            $locked = ProductionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ProductionBatchStatus::Reversed) {
                return [
                    'status' => 'skipped_reversed',
                    'batch_number' => $locked->batch_number,
                    'message' => 'Reversed batches are left unchanged.',
                ];
            }

            if ($locked->status !== ProductionBatchStatus::Completed) {
                throw ValidationException::withMessages([
                    'status' => 'Only completed production batches can be repaired for finished product output.',
                ]);
            }

            $actor = $user ?? $locked->supervisor ?? User::query()->findOrFail((int) $locked->supervisor_id);
            $removedWrong = [];
            $rmAdjusted = [];

            $wrongOutputs = StockLedger::query()
                ->where('reference_type', ProductionBatch::class)
                ->where('reference_id', $locked->id)
                ->where('quantity_in', '>', 0)
                ->where(function ($query) use ($locked): void {
                    $query->where(function ($q) use ($locked): void {
                        $q->where('transaction_type', StockTransactionType::ProductionOutput)
                            ->where(function ($inner) use ($locked): void {
                                $inner->where('item_type', '!=', StockItemType::FinishedProduct)
                                    ->orWhereNotNull('raw_material_id')
                                    ->orWhereNotNull('packaging_material_id')
                                    ->orWhereNull('product_id')
                                    ->orWhere('product_id', '!=', $locked->product_id);
                            });
                    })->orWhere(function ($q): void {
                        // Legacy mis-posts: production qty inward on RM/PM under the batch reference.
                        $q->whereIn('item_type', [
                            StockItemType::RawMaterial,
                            StockItemType::PackagingMaterial,
                        ])->where('transaction_type', '!=', StockTransactionType::BatchReversal)
                            ->where('transaction_type', '!=', StockTransactionType::ProductionConsumption);
                    });
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($wrongOutputs as $wrong) {
                if ($wrong->item_type === StockItemType::RawMaterial && $wrong->raw_material_id) {
                    $material = $this->inventoryService->lockRawMaterial((int) $wrong->raw_material_id);
                    $qty = round((float) $wrong->quantity_in, 3);
                    $before = (float) $material->current_stock;
                    $after = round(max(0, $before - $qty), 3);
                    $material->current_stock = $after;
                    $material->current_stock_value = round($after * (float) $material->average_rate, 2);
                    $material->save();
                    $rmAdjusted[] = [
                        'raw_material_id' => (int) $material->id,
                        'quantity_removed' => $qty,
                        'stock_after' => $after,
                    ];
                }

                if ($wrong->item_type === StockItemType::PackagingMaterial && $wrong->packaging_material_id) {
                    $material = $this->inventoryService->lockPackagingMaterial((int) $wrong->packaging_material_id);
                    $qty = round((float) $wrong->quantity_in, 3);
                    $after = round(max(0, (float) $material->current_stock - $qty), 3);
                    $material->current_stock = $after;
                    $material->current_stock_value = round($after * (float) $material->average_rate, 2);
                    $material->save();
                }

                $removedWrong[] = (int) $wrong->id;
                $wrong->delete();
            }

            $existing = $this->findExistingFinishedProductLedger($locked);
            $product = $this->inventoryService->lockProduct((int) $locked->product_id);

            if ($existing !== null) {
                if ($locked->finished_product_posted_at === null || $locked->finished_product_ledger_id === null) {
                    $this->stampBatchFromLedger($locked, $existing);
                }

                $this->ensureProductReflectsFinishedLedger($product, $existing);
                $product->manufacturing_enabled = true;
                $product->save();

                return [
                    'status' => $removedWrong === [] ? 'already_correct' : 'repaired_mapping',
                    'batch_number' => $locked->batch_number,
                    'message' => $removedWrong === []
                        ? 'Finished product output already mapped correctly; manufacturing flag ensured.'
                        : 'Removed incorrect non-FG output mapping(s); kept existing finished-product ledger.',
                    'quantity_added' => (float) $existing->quantity_in,
                    'batch_cost' => (float) ($existing->transaction_value ?? $locked->total_batch_cost),
                    'stock_before' => (float) ($locked->finished_stock_before ?? $existing->stock_before),
                    'stock_after' => (float) $product->fresh()->current_finished_stock,
                    'stock_value_after' => round(
                        (float) $product->fresh()->current_finished_stock * (float) $product->fresh()->weighted_average_cost,
                        2,
                    ),
                    'ledger_id' => $existing->id,
                    'removed_wrong_ledgers' => $removedWrong,
                    'rm_stock_adjusted' => $rmAdjusted,
                ];
            }

            // No FG ledger yet — post without re-consuming materials.
            $result = $this->backfillMissing($locked->fresh(), $actor);
            $result['removed_wrong_ledgers'] = $removedWrong;
            $result['rm_stock_adjusted'] = $rmAdjusted;
            $result['status'] = $removedWrong === [] ? $result['status'] : 'repaired_and_posted';
            $result['stock_value_after'] = round(
                (float) Product::query()->whereKey($locked->product_id)->value('current_finished_stock')
                * (float) Product::query()->whereKey($locked->product_id)->value('weighted_average_cost'),
                2,
            );

            return $result;
        });
    }

    private function ensureProductReflectsFinishedLedger(Product $product, StockLedger $ledger): void
    {
        $qty = round((float) $ledger->quantity_in, 3);
        $stockAfter = round((float) $ledger->stock_after, 3);
        $avg = (float) ($ledger->new_average_rate ?? $ledger->average_rate_after ?? $product->weighted_average_cost);

        // If product stock was never advanced (or was zeroed by report filters / flags),
        // align to the authoritative finished-product production ledger for this batch
        // without double-counting when stock already matches.
        if ((float) $product->current_finished_stock < $stockAfter - 0.0001
            && (float) $product->current_finished_stock < $qty - 0.0001) {
            $oldStock = (float) $product->current_finished_stock;
            $oldAvg = (float) $product->weighted_average_cost;
            $oldValue = round(max(0, $oldStock) * max(0, $oldAvg), 2);
            $batchCost = round((float) ($ledger->transaction_value ?? $ledger->inward_value ?? ($qty * $avg)), 2);
            $newStock = round($oldStock + $qty, 3);
            $newValue = round($oldValue + $batchCost, 2);
            $newAvg = $newStock > 0.0001 ? round($newValue / $newStock, 4) : 0.0;

            $product->current_finished_stock = $newStock;
            $product->weighted_average_cost = $newAvg;
            $product->latest_production_cost = round((float) ($ledger->rate ?? $avg), 4);
        } elseif ((float) $product->weighted_average_cost <= 0 && $avg > 0) {
            $product->weighted_average_cost = round($avg, 4);
            $product->latest_production_cost = round((float) ($ledger->rate ?? $avg), 4);
        }
    }

    private function stampBatchFromLedger(ProductionBatch $batch, StockLedger $ledger): void
    {
        $batch->fill([
            'finished_product_posted_at' => $batch->completed_at ?? $ledger->created_at ?? now(),
            'finished_stock_before' => (float) $ledger->stock_before,
            'finished_stock_after' => (float) $ledger->stock_after,
            'finished_stock_value_after' => (float) ($ledger->closing_value ?? round(
                (float) $ledger->stock_after * (float) ($ledger->average_rate_after ?? $ledger->new_average_rate ?? 0),
                2,
            )),
            'finished_product_ledger_id' => $ledger->id,
        ]);
        $batch->save();
    }
}
