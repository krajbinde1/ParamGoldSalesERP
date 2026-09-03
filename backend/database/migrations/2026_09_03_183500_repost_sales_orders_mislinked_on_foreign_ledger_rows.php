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
        // Mapping-only repair; cannot un-post the newly created sales-order debit safely.
    }
};
