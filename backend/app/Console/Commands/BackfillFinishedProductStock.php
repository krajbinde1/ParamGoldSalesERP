<?php

namespace App\Console\Commands;

use App\Enums\ProductionBatchStatus;
use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Models\ProductionBatch;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Inventory\FinishedProductPostingService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class BackfillFinishedProductStock extends Command
{
    protected $signature = 'inventory:backfill-finished-product-stock
                            {batch_number? : Specific production batch number (e.g. PB202607220001)}
                            {--all : Backfill every completed batch missing FG stock posting}
                            {--dry-run : Report actions without writing}';

    protected $description = 'Post missing Finished Product stock for completed production batches without re-consuming materials';

    public function handle(FinishedProductPostingService $postingService): int
    {
        $batchNumber = $this->argument('batch_number');
        $all = (bool) $this->option('all');
        $dryRun = (bool) $this->option('dry-run');

        if (! $batchNumber && ! $all) {
            $this->error('Provide a batch_number or use --all.');

            return self::FAILURE;
        }

        $query = ProductionBatch::query()->where('status', ProductionBatchStatus::Completed);

        if ($batchNumber) {
            $query->where('batch_number', $batchNumber);
        }

        $batches = $query->orderBy('id')->get();

        if ($batches->isEmpty()) {
            $this->warn($batchNumber
                ? "Batch {$batchNumber} not found (or not completed)."
                : 'No completed production batches found.');

            return self::SUCCESS;
        }

        $posted = 0;
        $already = 0;
        $failed = 0;

        foreach ($batches as $batch) {
            $existingLedger = StockLedger::query()
                ->where('item_type', StockItemType::FinishedProduct)
                ->where('transaction_type', StockTransactionType::ProductionOutput)
                ->where('reference_type', ProductionBatch::class)
                ->where('reference_id', $batch->id)
                ->first();

            $needsPosting = $existingLedger === null && $batch->finished_product_posted_at === null;

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] %s product_id=%s qty=%s cost=%s %s',
                    $batch->batch_number,
                    $batch->product_id,
                    $batch->actual_output_quantity,
                    $batch->total_batch_cost,
                    $needsPosting ? 'WOULD_POST' : 'ALREADY_POSTED',
                ));
                $needsPosting ? $posted++ : $already++;

                continue;
            }

            try {
                $user = $batch->supervisor
                    ?? User::query()->where('role', 'director')->first()
                    ?? User::query()->first();

                if ($user === null) {
                    throw ValidationException::withMessages([
                        'user' => 'No user available to attribute the backfill ledger entry.',
                    ]);
                }

                $result = $postingService->backfillMissing($batch, $user);

                if ($result['status'] === 'already_posted') {
                    $already++;
                    $this->info("{$result['batch_number']}: {$result['message']} (ledger #".($result['ledger_id'] ?? 'n/a').')');
                } else {
                    $posted++;
                    $this->info(sprintf(
                        '%s: posted qty=%s cost=%s stock %s → %s ledger #%s',
                        $result['batch_number'],
                        $result['quantity_added'] ?? 0,
                        $result['batch_cost'] ?? 0,
                        $result['stock_before'] ?? 0,
                        $result['stock_after'] ?? 0,
                        $result['ledger_id'] ?? 'n/a',
                    ));
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$batch->batch_number}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->table(
            ['Posted', 'Already Posted', 'Failed', 'Dry Run'],
            [[$posted, $already, $failed, $dryRun ? 'yes' : 'no']],
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
