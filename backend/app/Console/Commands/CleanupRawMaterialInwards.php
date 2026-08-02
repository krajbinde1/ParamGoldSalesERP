<?php

namespace App\Console\Commands;

use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Models\RawMaterial;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialInward;
use App\Models\RawMaterialInwardItem;
use App\Models\RawMaterialInwardReturn;
use App\Models\StockLedger;
use App\Services\Inventory\MaterialInwardCosting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One-off / reusable cleanup: purge ALL raw material inward test documents and
 * reverse their stock/ledger impact. Does not touch packaging inwards, masters,
 * opening stock (unless posted via inward), BOM, production, etc.
 */
class CleanupRawMaterialInwards extends Command
{
    protected $signature = 'inventory:cleanup-raw-material-inwards
                            {--force : Skip interactive confirmation}
                            {--dry-run : Show what would be deleted without writing}';

    protected $description = 'Delete all Raw Material Inward documents, related items/returns/batches/ledgers, and recalculate affected raw material stock balances';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->info('Database: '.config('database.default').' / '.config('database.connections.'.config('database.default').'.database'));

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->error('Cannot connect to database: '.$e->getMessage());

            return self::FAILURE;
        }

        $inwards = RawMaterialInward::query()->with(['items', 'returns', 'batches'])->orderBy('id')->get();
        $inwardIds = $inwards->pluck('id')->all();
        $itemIds = $inwards->flatMap(fn (RawMaterialInward $i) => $i->items->pluck('id'))->unique()->values()->all();
        $returnIds = RawMaterialInwardReturn::query()
            ->when($inwardIds !== [], fn ($q) => $q->whereIn('raw_material_inward_id', $inwardIds))
            ->when($inwardIds === [], fn ($q) => $q->whereRaw('1 = 0'))
            ->pluck('id')
            ->all();

        $ledgersToDelete = StockLedger::query()
            ->where('item_type', StockItemType::RawMaterial)
            ->where(function ($q) use ($inwardIds, $returnIds) {
                $q->where('transaction_type', StockTransactionType::RawMaterialInward);

                if ($inwardIds !== []) {
                    $q->orWhere(function ($inner) use ($inwardIds) {
                        $inner->where('reference_type', RawMaterialInward::class)
                            ->whereIn('reference_id', $inwardIds);
                    });
                }

                if ($returnIds !== []) {
                    $q->orWhere(function ($inner) use ($returnIds) {
                        $inner->where('reference_type', RawMaterialInwardReturn::class)
                            ->whereIn('reference_id', $returnIds);
                    });
                }
            })
            ->orderBy('id')
            ->get();

        $batchIds = RawMaterialBatch::query()
            ->when($inwardIds !== [], fn ($q) => $q->whereIn('inward_id', $inwardIds))
            ->when($inwardIds === [], fn ($q) => $q->whereRaw('1 = 0'))
            ->pluck('id')
            ->all();

        $affectedMaterialIds = collect()
            ->merge($inwards->flatMap(fn (RawMaterialInward $i) => $i->items->pluck('raw_material_id')))
            ->merge($ledgersToDelete->pluck('raw_material_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Raw material inwards', (string) $inwards->count()],
                ['Inward items', (string) count($itemIds)],
                ['Inward returns', (string) count($returnIds)],
                ['Inward-linked batches', (string) count($batchIds)],
                ['Stock ledger rows to delete', (string) $ledgersToDelete->count()],
                ['Affected raw materials', (string) count($affectedMaterialIds)],
                ['Raw material masters (untouched)', (string) RawMaterial::query()->count()],
            ],
        );

        if ($inwards->isEmpty() && $ledgersToDelete->isEmpty()) {
            $this->info('Nothing to clean up.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Dry run — no changes written.');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm('Permanently delete all Raw Material Inward data and reverse stock impact?', false)) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $counts = [
            'inwards' => 0,
            'items' => 0,
            'returns' => 0,
            'batches' => 0,
            'ledgers' => 0,
            'attachments_removed' => 0,
            'materials_recalculated' => 0,
        ];

        try {
            DB::transaction(function () use (
                $inwardIds,
                $returnIds,
                $batchIds,
                $ledgersToDelete,
                $affectedMaterialIds,
                $inwards,
                &$counts,
            ) {
                // 1) Delete stock ledgers linked to inwards / inward returns first.
                $ledgerIds = $ledgersToDelete->pluck('id')->all();
                if ($ledgerIds !== []) {
                    $counts['ledgers'] = StockLedger::query()->whereIn('id', $ledgerIds)->delete();
                }

                // 2) Delete returns (FK restrictOnDelete on inward).
                if ($returnIds !== []) {
                    $counts['returns'] = RawMaterialInwardReturn::query()->whereIn('id', $returnIds)->delete();
                }

                // 3) Delete batches created from those inwards.
                if ($batchIds !== []) {
                    $counts['batches'] = RawMaterialBatch::query()->whereIn('id', $batchIds)->delete();
                }

                // 4) Count items before cascade, then delete headers (items cascade).
                $counts['items'] = $inwardIds === []
                    ? 0
                    : RawMaterialInwardItem::query()->whereIn('raw_material_inward_id', $inwardIds)->count();

                // Remove stored attachments if present.
                foreach ($inwards as $inward) {
                    if (filled($inward->attachment_path) && Storage::disk('public')->exists($inward->attachment_path)) {
                        Storage::disk('public')->delete($inward->attachment_path);
                        $counts['attachments_removed']++;
                    }
                }

                if ($inwardIds !== []) {
                    // Explicit item delete then header delete (no SoftDeletes on these models).
                    RawMaterialInwardItem::query()->whereIn('raw_material_inward_id', $inwardIds)->delete();
                    $counts['inwards'] = RawMaterialInward::query()->whereIn('id', $inwardIds)->delete();
                }

                // Safety: purge any leftover RMI-typed ledgers for raw materials.
                $orphanLedgers = StockLedger::query()
                    ->where('item_type', StockItemType::RawMaterial)
                    ->where(function ($q) {
                        $q->where('transaction_type', StockTransactionType::RawMaterialInward)
                            ->orWhere('reference_type', RawMaterialInward::class)
                            ->orWhere('reference_type', RawMaterialInwardReturn::class);
                    })
                    ->count();

                if ($orphanLedgers > 0) {
                    $extra = StockLedger::query()
                        ->where('item_type', StockItemType::RawMaterial)
                        ->where(function ($q) {
                            $q->where('transaction_type', StockTransactionType::RawMaterialInward)
                                ->orWhere('reference_type', RawMaterialInward::class)
                                ->orWhere('reference_type', RawMaterialInwardReturn::class);
                        })
                        ->delete();
                    $counts['ledgers'] += $extra;
                }

                // 5) Recalculate each affected raw material from remaining ledgers.
                $costing = new MaterialInwardCosting;
                $materialIds = $affectedMaterialIds !== []
                    ? $affectedMaterialIds
                    : RawMaterial::query()->pluck('id')->all();

                foreach ($materialIds as $materialId) {
                    $material = RawMaterial::query()->whereKey($materialId)->lockForUpdate()->first();
                    if (! $material) {
                        continue;
                    }

                    $this->recalculateRawMaterialFromLedgers($material, $costing);
                    $counts['materials_recalculated']++;
                }
            });
        } catch (Throwable $e) {
            $this->error('Cleanup failed and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Cleanup committed.');
        $this->table(
            ['Deleted / updated', 'Count'],
            [
                ['Inwards deleted', (string) $counts['inwards']],
                ['Items deleted', (string) $counts['items']],
                ['Returns deleted', (string) $counts['returns']],
                ['Batches deleted', (string) $counts['batches']],
                ['Ledger rows deleted', (string) $counts['ledgers']],
                ['Attachments removed', (string) $counts['attachments_removed']],
                ['Materials recalculated', (string) $counts['materials_recalculated']],
            ],
        );

        return $this->verify($counts) ? self::SUCCESS : self::FAILURE;
    }

    private function recalculateRawMaterialFromLedgers(RawMaterial $material, MaterialInwardCosting $costing): void
    {
        $ledgers = StockLedger::query()
            ->where('item_type', StockItemType::RawMaterial)
            ->where('raw_material_id', $material->id)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $stock = 0.0;
        $avg = 0.0;
        $lastPurchaseRate = (float) $material->purchase_rate;

        foreach ($ledgers as $ledger) {
            $qtyIn = (float) $ledger->quantity_in;
            $qtyOut = (float) $ledger->quantity_out;
            $rate = (float) $ledger->rate;

            if ($qtyIn > 0) {
                $avg = $costing->calculateWeightedAverageRate($stock, $avg, $qtyIn, $rate);
                $stock = round($stock + $qtyIn, 3);

                if (in_array($ledger->transaction_type, [
                    StockTransactionType::RawMaterialInward,
                    StockTransactionType::Purchase,
                    StockTransactionType::OpeningStock,
                ], true) && $rate > 0) {
                    $lastPurchaseRate = $rate;
                }
            }

            if ($qtyOut > 0) {
                $stock = round($stock - $qtyOut, 3);
                if ($stock <= 0.0001) {
                    $stock = 0.0;
                    $avg = 0.0;
                }
            }

            // Historical ledger stock_before/after snapshots are left unchanged.
        }

        if ($stock <= 0.0001) {
            $stock = 0.0;
            $avg = 0.0;
            $value = 0.0;
        } else {
            $value = round($stock * $avg, 2);
        }

        $material->current_stock = $stock;
        $material->average_rate = round($avg, 4);
        $material->current_stock_value = $value;
        // Keep last known non-inward purchase rate when possible; if stock remains from opening only, opening rate is fine.
        if ($lastPurchaseRate > 0) {
            $material->purchase_rate = round($lastPurchaseRate, 4);
        }
        $material->save();
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function verify(array $counts): bool
    {
        $this->newLine();
        $this->info('Verification');

        $inwardCount = RawMaterialInward::query()->count();
        $itemCount = RawMaterialInwardItem::query()->count();
        $returnCount = RawMaterialInwardReturn::query()->count();
        $rmiLedgers = StockLedger::query()
            ->where('item_type', StockItemType::RawMaterial)
            ->where(function ($q) {
                $q->where('transaction_type', StockTransactionType::RawMaterialInward)
                    ->orWhere('reference_type', RawMaterialInward::class)
                    ->orWhere('reference_type', RawMaterialInwardReturn::class);
            })
            ->count();

        $openingKept = StockLedger::query()
            ->where('item_type', StockItemType::RawMaterial)
            ->where('transaction_type', StockTransactionType::OpeningStock)
            ->count();

        $mismatches = [];
        foreach (RawMaterial::query()->orderBy('id')->get() as $material) {
            $net = (float) StockLedger::query()
                ->where('raw_material_id', $material->id)
                ->where('item_type', StockItemType::RawMaterial)
                ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as net')
                ->value('net');
            $net = round(max(0, $net), 3);
            $stock = round((float) $material->current_stock, 3);
            $valueOk = abs((float) $material->current_stock_value - round($stock * (float) $material->average_rate, 2)) < 0.02;

            if (abs($stock - $net) > 0.001 || ! $valueOk) {
                $mismatches[] = "RM #{$material->id} {$material->material_code}: stock={$stock} ledger_net={$net} avg={$material->average_rate} value={$material->current_stock_value}";
            }
        }

        $fkOk = true;
        try {
            // Orphan check: ledger refs to missing inwards / returns.
            $orphanInwardRefs = StockLedger::query()
                ->where('reference_type', RawMaterialInward::class)
                ->whereNotIn('reference_id', RawMaterialInward::query()->select('id'))
                ->count();
            $orphanReturnRefs = StockLedger::query()
                ->where('reference_type', RawMaterialInwardReturn::class)
                ->whereNotIn('reference_id', RawMaterialInwardReturn::query()->select('id'))
                ->count();
            $orphanBatches = RawMaterialBatch::query()
                ->whereNotNull('inward_id')
                ->whereNotIn('inward_id', RawMaterialInward::query()->select('id'))
                ->count();

            if ($orphanInwardRefs > 0 || $orphanReturnRefs > 0 || $orphanBatches > 0) {
                $fkOk = false;
                $this->error("Orphans: inward_refs={$orphanInwardRefs}, return_refs={$orphanReturnRefs}, batches={$orphanBatches}");
            }
        } catch (Throwable $e) {
            $fkOk = false;
            $this->error('FK integrity check failed: '.$e->getMessage());
        }

        $this->table(
            ['Check', 'Result'],
            [
                ['raw_material_inwards empty', $inwardCount === 0 ? 'PASS (0)' : "FAIL ({$inwardCount})"],
                ['raw_material_inward_items empty', $itemCount === 0 ? 'PASS (0)' : "FAIL ({$itemCount})"],
                ['raw_material_inward_returns empty', $returnCount === 0 ? 'PASS (0)' : "FAIL ({$returnCount})"],
                ['No RMI/return ledger orphans', $rmiLedgers === 0 ? 'PASS (0)' : "FAIL ({$rmiLedgers})"],
                ['Opening stock ledgers kept', "INFO ({$openingKept})"],
                ['Stock matches remaining ledgers', $mismatches === [] ? 'PASS' : 'FAIL'],
                ['FK / orphan integrity', $fkOk ? 'PASS' : 'FAIL'],
                ['Reported inwards deleted', (string) $counts['inwards']],
                ['Reported items deleted', (string) $counts['items']],
                ['Reported ledgers deleted', (string) $counts['ledgers']],
            ],
        );

        if ($mismatches !== []) {
            foreach ($mismatches as $line) {
                $this->error($line);
            }
        }

        foreach (RawMaterial::query()->orderBy('id')->get() as $material) {
            $this->line(sprintf(
                '  %s stock=%s avg=%s value=%s opening_master=%s',
                $material->material_code,
                $material->current_stock,
                $material->average_rate,
                $material->current_stock_value,
                $material->opening_stock,
            ));
        }

        return $inwardCount === 0
            && $itemCount === 0
            && $rmiLedgers === 0
            && $mismatches === []
            && $fkOk;
    }
}
