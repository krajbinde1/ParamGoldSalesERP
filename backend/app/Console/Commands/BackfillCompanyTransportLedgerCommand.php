<?php

namespace App\Console\Commands;

use App\Services\Orders\CompanyTransportLedgerService;
use Illuminate\Console\Command;

class BackfillCompanyTransportLedgerCommand extends Command
{
    protected $signature = 'ledger:backfill-company-transport';

    protected $description = 'Post missing transport ledger credits for dispatched sales orders (Company Transport and Transport Charges Extra)';

    public function handle(CompanyTransportLedgerService $ledger): int
    {
        $result = $ledger->backfillDispatchedOrderCredits();

        $this->info('Posted transport credits: '.$result['posted']);
        $this->info('Skipped (already posted or ineligible): '.$result['skipped']);

        return self::SUCCESS;
    }
}
