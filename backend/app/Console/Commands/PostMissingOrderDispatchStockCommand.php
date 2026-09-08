<?php

namespace App\Console\Commands;

use App\Services\Inventory\OrderDispatchStockService;
use Illuminate\Console\Command;

class PostMissingOrderDispatchStockCommand extends Command
{
    protected $signature = 'inventory:post-missing-order-dispatch-stock
                            {order? : Specific sales order id}
                            {--all : Post missing FG dispatch stock for every eligible dispatched order}
                            {--dry-run : Report which orders would be posted without writing}';

    protected $description = 'Post missing Finished Product stock deductions for dispatched sales orders that have no dispatch ledger (never double-posts)';

    public function handle(OrderDispatchStockService $stock): int
    {
        $orderId = $this->argument('order');
        $all = (bool) $this->option('all');
        $dryRun = (bool) $this->option('dry-run');

        if (! $orderId && ! $all) {
            $this->error('Provide an order id or use --all.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Dry-run: no stock ledgers will be written.');
            $this->info('Pass an order id or --all without --dry-run to post missing dispatch stock.');

            return self::SUCCESS;
        }

        $result = $stock->postMissingForDispatchedOrders(
            $orderId !== null ? (int) $orderId : null,
        );

        $this->info('Posted missing dispatch stock for orders: '.$result['posted']);
        $this->info('Skipped (already posted or ineligible): '.$result['skipped']);

        return self::SUCCESS;
    }
}
