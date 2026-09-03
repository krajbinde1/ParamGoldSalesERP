<?php

use App\Services\Dealers\DealerLedgerPostingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('dealer_tally_entries')) {
            return;
        }

        app(DealerLedgerPostingService::class)->backfill();
    }

    public function down(): void
    {
        // Missing billed/dispatched sales-order ledger rows cannot be un-posted safely.
    }
};
