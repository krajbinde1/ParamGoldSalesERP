<?php

namespace App\Services\Inventory;

use App\Enums\BomOutputType;
use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Models\Bom;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * Restores missing finished-product_id on production batches when the product
 * can be identified uniquely from the linked BOM and/or production-output ledger.
 *
 * Does not change quantities, BOM rows, or stock ledger rows.
 * Never writes product_id onto semi-finished / bulk batches.
 */
final class ProductionBatchProductBackfillService
{
    /**
     * @return array{
     *     scanned: int,
     *     updated: int,
     *     skipped_sfg: int,
     *     skipped_has_product: int,
     *     skipped_unidentified: int,
     *     skipped_conflict: int,
     *     updates: list<array{batch_number: string, product_id: int, source: string}>
     * }
     */
    public function backfill(bool $dryRun = false, ?string $batchNumber = null): array
    {
        $query = ProductionBatch::query()
            ->with(['bom:id,product_id,semi_finished_id,output_type'])
            ->orderBy('id');

        if (filled($batchNumber)) {
            $query->where('batch_number', $batchNumber);
        }

        $report = [
            'scanned' => 0,
            'updated' => 0,
            'skipped_sfg' => 0,
            'skipped_has_product' => 0,
            'skipped_unidentified' => 0,
            'skipped_conflict' => 0,
            'updates' => [],
        ];

        foreach ($query->cursor() as $batch) {
            $report['scanned']++;

            $identified = $this->identifyFinishedProductId($batch);

            if ($identified['status'] === 'has_product') {
                $report['skipped_has_product']++;

                continue;
            }

            if ($identified['status'] === 'sfg') {
                $report['skipped_sfg']++;

                continue;
            }

            if ($identified['status'] === 'conflict') {
                $report['skipped_conflict']++;

                continue;
            }

            if ($identified['status'] !== 'identified' || $identified['product_id'] === null) {
                $report['skipped_unidentified']++;

                continue;
            }

            $report['updates'][] = [
                'batch_number' => (string) $batch->batch_number,
                'product_id' => $identified['product_id'],
                'source' => $identified['source'],
            ];

            if (! $dryRun) {
                DB::table('production_batches')
                    ->where('id', $batch->id)
                    ->where(function ($q): void {
                        $q->whereNull('product_id')->orWhere('product_id', 0);
                    })
                    ->update(['product_id' => $identified['product_id']]);
            }

            $report['updated']++;
        }

        return $report;
    }

    /**
     * @return array{status: string, product_id: ?int, source: string}
     */
    public function identifyFinishedProductId(ProductionBatch $batch): array
    {
        if ($batch->outputType() === BomOutputType::SemiFinished || (int) $batch->semi_finished_id > 0) {
            return ['status' => 'sfg', 'product_id' => null, 'source' => 'semi_finished'];
        }

        if ((int) $batch->product_id > 0) {
            return ['status' => 'has_product', 'product_id' => (int) $batch->product_id, 'source' => 'batch'];
        }

        $sources = [];

        $bomProductId = (int) ($batch->bom?->product_id
            ?? ($batch->bom_id ? Bom::query()->whereKey($batch->bom_id)->value('product_id') : 0));
        if ($bomProductId > 0) {
            $sources['bom'] = $bomProductId;
        }

        $ledgerProductIds = StockLedger::query()
            ->where('reference_type', ProductionBatch::class)
            ->where('reference_id', $batch->id)
            ->where('item_type', StockItemType::FinishedProduct)
            ->where('transaction_type', StockTransactionType::ProductionOutput)
            ->whereNotNull('product_id')
            ->distinct()
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($batch->finished_product_ledger_id) {
            $fromStamp = (int) StockLedger::query()
                ->whereKey($batch->finished_product_ledger_id)
                ->value('product_id');
            if ($fromStamp > 0 && ! $ledgerProductIds->contains($fromStamp)) {
                $ledgerProductIds = $ledgerProductIds->push($fromStamp)->unique()->values();
            }
        }

        if ($ledgerProductIds->count() > 1) {
            return ['status' => 'conflict', 'product_id' => null, 'source' => 'ledger'];
        }

        if ($ledgerProductIds->count() === 1) {
            $sources['ledger'] = (int) $ledgerProductIds->first();
        }

        $uniqueIds = array_values(array_unique(array_values($sources)));

        if ($uniqueIds === []) {
            return ['status' => 'unidentified', 'product_id' => null, 'source' => ''];
        }

        if (count($uniqueIds) !== 1) {
            return ['status' => 'conflict', 'product_id' => null, 'source' => implode(',', array_keys($sources))];
        }

        $productId = $uniqueIds[0];
        if (! Product::query()->withTrashed()->whereKey($productId)->exists()) {
            return ['status' => 'unidentified', 'product_id' => null, 'source' => ''];
        }

        return [
            'status' => 'identified',
            'product_id' => $productId,
            'source' => implode(',', array_keys($sources)),
        ];
    }
}
