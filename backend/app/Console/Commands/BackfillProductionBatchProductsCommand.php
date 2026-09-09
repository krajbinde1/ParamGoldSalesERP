<?php

namespace App\Console\Commands;

use App\Services\Inventory\ProductionBatchProductBackfillService;
use Illuminate\Console\Command;

class BackfillProductionBatchProductsCommand extends Command
{
    protected $signature = 'production:backfill-batch-products
                            {--dry-run : Report identifiable product_id values without writing}
                            {--batch= : Limit to one batch_number (e.g. PB202609090001)}';

    protected $description = 'Fill missing finished product_id on production batches when BOM/ledger identity is unique. Does not change qty, BOM, or stock.';

    public function handle(ProductionBatchProductBackfillService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $batchNumber = filled($this->option('batch')) ? (string) $this->option('batch') : null;

        $report = $service->backfill($dryRun, $batchNumber);

        $this->info($dryRun ? 'Dry run (no writes)' : 'Backfill complete');
        $this->info('Scanned: '.$report['scanned']);
        $this->info('Updated: '.$report['updated']);
        $this->info('Skipped (already has product): '.$report['skipped_has_product']);
        $this->info('Skipped (semi-finished / bulk): '.$report['skipped_sfg']);
        $this->info('Skipped (unidentified): '.$report['skipped_unidentified']);
        $this->info('Skipped (conflict): '.$report['skipped_conflict']);

        if ($report['updates'] !== []) {
            $this->newLine();
            $this->table(
                ['Batch Number', 'Product ID', 'Source'],
                array_map(fn (array $row): array => [
                    $row['batch_number'],
                    $row['product_id'],
                    $row['source'],
                ], $report['updates']),
            );
        }

        return self::SUCCESS;
    }
}
