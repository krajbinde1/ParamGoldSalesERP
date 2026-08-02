<?php

namespace App\Services\Inventory;

use App\Enums\BomOutputType;
use App\Enums\ProductionBatchStatus;
use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Models\ProductionBatch;
use App\Models\SemiFinishedMaterial;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts semi-finished stock for a production batch (idempotent).
 * Mirrors finished-product WAVG posting without overloading product_id.
 */
final class SemiFinishedPostingService
{
    public function __construct(
        private readonly StockLedgerService $ledgerService = new StockLedgerService,
        private readonly InventoryService $inventoryService = new InventoryService,
    ) {}

    public function hasPosted(ProductionBatch $batch): bool
    {
        if ($batch->semi_finished_posted_at !== null || $batch->semi_finished_ledger_id !== null) {
            return true;
        }

        return $this->findExistingSemiFinishedLedger($batch) !== null;
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
     * Caller must already hold a lock on $material and be inside a DB transaction.
     */
    public function postFromProduction(
        ProductionBatch $batch,
        SemiFinishedMaterial $material,
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
                'actual_output_quantity' => 'Production quantity must be greater than zero to post semi-finished stock.',
            ]);
        }

        $oldStock = (float) $material->current_stock;
        $oldAvg = (float) $material->average_production_cost;
        $oldValue = round(max(0, $oldStock) * max(0, $oldAvg), 2);

        $newStock = round($oldStock + $quantity, 3);
        $newValue = round($oldValue + $batchCost, 2);
        $newAvg = $newStock > 0.0001
            ? round($newValue / $newStock, 4)
            : 0.0;

        $particulars = 'Semi-Finished Production Batch '.$batch->batch_number;

        $ledger = $this->ledgerService->postSemiFinishedMovement(
            $material,
            $quantity,
            0,
            $costPerUnit,
            [
                'transaction_date' => $transactionDate,
                'transaction_type' => StockTransactionType::SemiFinishedProduction,
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

        $material->refresh();
        // Stock and WAVG already applied inside postSemiFinishedMovement when new_average_rate is provided.

        $batch->fill([
            'output_type' => BomOutputType::SemiFinished->value,
            'semi_finished_id' => $material->id,
            'semi_finished_posted_at' => now(),
            'semi_finished_stock_before' => $oldStock,
            'semi_finished_stock_after' => $newStock,
            'semi_finished_stock_value_after' => $newValue,
            'semi_finished_ledger_id' => $ledger->id,
        ]);
        $batch->save();

        return $ledger;
    }

    public function findExistingSemiFinishedLedger(ProductionBatch $batch): ?StockLedger
    {
        if ($batch->semi_finished_ledger_id) {
            $byId = StockLedger::query()->find($batch->semi_finished_ledger_id);
            if ($byId !== null) {
                return $byId;
            }
        }

        return StockLedger::query()
            ->where('item_type', StockItemType::SemiFinished)
            ->where('transaction_type', StockTransactionType::SemiFinishedProduction)
            ->where('reference_type', ProductionBatch::class)
            ->where('reference_id', $batch->id)
            ->where('semi_finished_id', $batch->semi_finished_id)
            ->whereNull('product_id')
            ->whereNull('raw_material_id')
            ->whereNull('packaging_material_id')
            ->orderBy('id')
            ->first();
    }
}
