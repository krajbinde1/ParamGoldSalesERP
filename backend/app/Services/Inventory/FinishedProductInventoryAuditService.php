<?php

namespace App\Services\Inventory;

use App\Enums\ProductionBatchStatus;
use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Models\ProductionBatch;
use App\Models\StockLedger;

/**
 * Read-only Finished Product stock reconciliation from 06-09-2026.
 * Does not write ledgers, opening stock, or dispatch history.
 */
final class FinishedProductInventoryAuditService
{
    public function __construct(
        private readonly OrderDispatchStockService $dispatchStock = new OrderDispatchStockService,
    ) {}

    /**
     * @return array{
     *     total_products: int,
     *     match: int,
     *     mismatch: int,
     *     negative: int,
     *     missing_dispatch_outwards: int,
     *     missing_inwards_production: int,
     *     reconciliation_start_date: string,
     *     product_effects: list<array<string, mixed>>,
     *     missing_dispatch_lines: list<array<string, mixed>>,
     *     missing_production: list<array<string, mixed>>
     * }
     */
    public function report(): array
    {
        $audit = $this->dispatchStock->auditMissing(null, [], true);
        $missingProduction = $this->detectMissingProductionInwards();

        $match = 0;
        $mismatch = 0;
        $negative = 0;
        foreach ($audit['product_effects'] as $row) {
            match ((string) $row['status']) {
                'MATCH' => $match++,
                'NEGATIVE' => $negative++,
                default => $mismatch++,
            };
        }

        return [
            'total_products' => count($audit['product_effects']),
            'match' => $match,
            'mismatch' => $mismatch,
            'negative' => $negative,
            'missing_dispatch_outwards' => (int) $audit['missing_lines'],
            'missing_inwards_production' => count($missingProduction),
            'reconciliation_start_date' => $audit['reconciliation_start_date'],
            'product_effects' => $audit['product_effects'],
            'missing_dispatch_lines' => $audit['missing'],
            'missing_production' => $missingProduction,
        ];
    }

    /**
     * Completed FG production batches with output qty and no production_output ledger.
     *
     * @return list<array<string, mixed>>
     */
    public function detectMissingProductionInwards(): array
    {
        $batches = ProductionBatch::query()
            ->where('status', ProductionBatchStatus::Completed)
            ->where('actual_output_quantity', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('semi_finished_id')
                    ->orWhere('semi_finished_id', 0);
            })
            ->with('product:id,product_name,product_code')
            ->orderBy('id')
            ->get();

        $missing = [];
        foreach ($batches as $batch) {
            $hasLedger = StockLedger::query()
                ->where('item_type', StockItemType::FinishedProduct)
                ->where('transaction_type', StockTransactionType::ProductionOutput)
                ->where('reference_type', ProductionBatch::class)
                ->where('reference_id', $batch->id)
                ->where('quantity_in', '>', 0)
                ->exists();

            if ($hasLedger) {
                continue;
            }

            $missing[] = [
                'batch_id' => (int) $batch->id,
                'batch_number' => (string) $batch->batch_number,
                'product_id' => (int) ($batch->product_id ?? 0),
                'product_name' => (string) ($batch->product?->product_name ?? 'Product #'.$batch->product_id),
                'qty' => round((float) $batch->actual_output_quantity, 3),
                'production_date' => $batch->production_date?->toDateString()
                    ?? $batch->manufacturing_date?->toDateString(),
            ];
        }

        return $missing;
    }
}
