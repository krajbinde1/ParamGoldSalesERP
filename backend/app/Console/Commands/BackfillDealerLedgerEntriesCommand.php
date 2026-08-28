<?php

namespace App\Console\Commands;

use App\Services\Dealers\DealerLedgerPostingService;
use Illuminate\Console\Command;

class BackfillDealerLedgerEntriesCommand extends Command
{
    protected $signature = 'ledger:backfill-erp-entries';

    protected $description = 'Post missing dealer ledger debits/credits for dispatched orders and received collections';

    public function handle(DealerLedgerPostingService $posting): int
    {
        $result = $posting->backfill();

        $this->info('Posted dispatched orders: '.$result['orders']);
        $this->info('Posted received collections: '.$result['collections']);

        return self::SUCCESS;
    }
}
