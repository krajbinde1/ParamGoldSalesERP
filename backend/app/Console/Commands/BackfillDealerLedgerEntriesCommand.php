<?php

namespace App\Console\Commands;

use App\Services\Dealers\DealerLedgerPostingService;
use Illuminate\Console\Command;

class BackfillDealerLedgerEntriesCommand extends Command
{
    protected $signature = 'ledger:backfill-erp-entries';

    protected $description = 'Post missing dealer ledger entries and reconcile Tally sales bills with ERP sales orders';

    public function handle(DealerLedgerPostingService $posting): int
    {
        $result = $posting->backfill();

        $this->info('Posted dispatched orders: '.$result['orders']);
        $this->info('Posted received collections: '.$result['collections']);
        $this->info('Reconciled Tally sales with ERP sales orders: '.$result['sales_reconciled']);

        return self::SUCCESS;
    }
}
