<?php

namespace App\Services\Inventory;

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\ProductionBatchStatus;
use App\Enums\StockTransactionType;
use App\Models\ProductionBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BatchReversalService
{
    public function __construct(
        private readonly StockLedgerService $ledgerService = new StockLedgerService,
        private readonly InventoryService $inventoryService = new InventoryService,
    ) {}

    public function reverse(ProductionBatch $batch, string $reason, User $director): ProductionBatch
    {
        if (! $director->isDirectorUser() && ! $director->isAdminUser()) {
            throw ValidationException::withMessages([
                'authorization' => 'Only Director or Admin can reverse a completed production batch.',
            ]);
        }

        if ($batch->status !== ProductionBatchStatus::Completed) {
            throw ValidationException::withMessages([
                'status' => 'Only completed production batches can be reversed.',
            ]);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'reversal_reason' => 'Reversal reason is required.',
            ]);
        }

        return DB::transaction(function () use ($batch, $reason, $director) {
            /** @var ProductionBatch $locked */
            $locked = ProductionBatch::query()
                ->with('consumptions')
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== ProductionBatchStatus::Completed) {
                throw ValidationException::withMessages([
                    'status' => 'Only completed production batches can be reversed.',
                ]);
            }

            $outputQty = (float) $locked->actual_output_quantity;
            $isSemiFinishedOutput = (string) $locked->output_type === BomOutputType::SemiFinished->value
                || filled($locked->semi_finished_id);

            if ($isSemiFinishedOutput) {
                $material = $this->inventoryService->lockSemiFinishedMaterial((int) $locked->semi_finished_id);
                $available = (float) $material->current_stock;

                if ($available + 0.0001 < $outputQty) {
                    throw ValidationException::withMessages([
                        'stock' => 'Cannot reverse batch. Semi-finished stock is less than the batch output quantity.',
                    ]);
                }

                $this->ledgerService->postSemiFinishedMovement(
                    $material,
                    0,
                    $outputQty,
                    (float) ($locked->cost_per_unit ?: $material->average_production_cost),
                    [
                        'transaction_date' => now('Asia/Kolkata')->toDateString(),
                        'transaction_type' => StockTransactionType::BatchReversal,
                        'reference_type' => ProductionBatch::class,
                        'reference_id' => $locked->id,
                        'reference_number' => $locked->batch_number,
                        'batch_number' => $locked->batch_number,
                        'remarks' => 'Batch reversal — semi-finished stock deduction',
                    ],
                    $director,
                );
            } else {
                $product = $this->inventoryService->lockProduct((int) $locked->product_id);
                $available = (float) $product->current_finished_stock;

                if ($available + 0.0001 < $outputQty) {
                    throw ValidationException::withMessages([
                        'stock' => 'Cannot reverse batch. Finished product stock is less than the batch output quantity.',
                    ]);
                }

                $this->ledgerService->postFinishedProductMovement(
                    $product,
                    0,
                    $outputQty,
                    (float) ($locked->cost_per_unit ?: $locked->cost_per_pack),
                    [
                        'transaction_date' => now('Asia/Kolkata')->toDateString(),
                        'transaction_type' => StockTransactionType::BatchReversal,
                        'reference_type' => ProductionBatch::class,
                        'reference_id' => $locked->id,
                        'reference_number' => $locked->batch_number,
                        'batch_number' => $locked->batch_number,
                        'remarks' => 'Batch reversal — finished stock deduction',
                    ],
                    $director,
                );
            }

            foreach ($locked->consumptions as $consumption) {
                $qty = (float) $consumption->consumed_quantity;
                $rate = (float) $consumption->rate;

                if ($consumption->item_type === BomItemType::RawMaterial) {
                    $material = $this->inventoryService->lockRawMaterial((int) $consumption->raw_material_id);
                    $this->ledgerService->postRawMaterialMovement(
                        $material,
                        $qty,
                        0,
                        $rate,
                        [
                            'transaction_date' => now('Asia/Kolkata')->toDateString(),
                            'transaction_type' => StockTransactionType::BatchReversal,
                            'reference_type' => ProductionBatch::class,
                            'reference_id' => $locked->id,
                            'reference_number' => $locked->batch_number,
                            'batch_number' => $locked->batch_number,
                            'remarks' => 'Batch reversal — restore raw material',
                        ],
                        $director,
                    );
                } elseif ($consumption->item_type === BomItemType::PackagingMaterial) {
                    $material = $this->inventoryService->lockPackagingMaterial((int) $consumption->packaging_material_id);
                    $this->ledgerService->postPackagingMaterialMovement(
                        $material,
                        $qty,
                        0,
                        $rate,
                        [
                            'transaction_date' => now('Asia/Kolkata')->toDateString(),
                            'transaction_type' => StockTransactionType::BatchReversal,
                            'reference_type' => ProductionBatch::class,
                            'reference_id' => $locked->id,
                            'reference_number' => $locked->batch_number,
                            'batch_number' => $locked->batch_number,
                            'remarks' => 'Batch reversal — restore packaging material',
                        ],
                        $director,
                    );
                } elseif ($consumption->item_type === BomItemType::SemiFinished) {
                    $material = $this->inventoryService->lockSemiFinishedMaterial((int) $consumption->semi_finished_id);
                    $this->ledgerService->postSemiFinishedMovement(
                        $material,
                        $qty,
                        0,
                        $rate,
                        [
                            'transaction_date' => now('Asia/Kolkata')->toDateString(),
                            'transaction_type' => StockTransactionType::BatchReversal,
                            'reference_type' => ProductionBatch::class,
                            'reference_id' => $locked->id,
                            'reference_number' => $locked->batch_number,
                            'batch_number' => $locked->batch_number,
                            'remarks' => 'Batch reversal — restore semi-finished material',
                        ],
                        $director,
                    );
                }
            }

            $locked->fill([
                'status' => ProductionBatchStatus::Reversed,
                'reversal_reason' => $reason,
                'reversed_by' => $director->id,
                'reversed_at' => now(),
                'finished_product_posted_at' => null,
                'finished_stock_before' => null,
                'finished_stock_after' => null,
                'finished_stock_value_after' => null,
                'finished_product_ledger_id' => null,
                'semi_finished_posted_at' => null,
                'semi_finished_stock_before' => null,
                'semi_finished_stock_after' => null,
                'semi_finished_stock_value_after' => null,
                'semi_finished_ledger_id' => null,
            ]);
            $locked->save();

            return $locked->fresh(['product', 'semiFinished', 'bom', 'consumptions', 'supervisor', 'reversedBy']);
        });
    }
}
