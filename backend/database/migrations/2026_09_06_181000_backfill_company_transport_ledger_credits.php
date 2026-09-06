<?php

use App\Services\Orders\CompanyTransportLedgerService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('orders')
            || ! Schema::hasTable('company_transport_ledger_entries')
            || ! Schema::hasColumn('company_transport_ledger_entries', 'transport_charge_type')
        ) {
            return;
        }

        app(CompanyTransportLedgerService::class)->backfillDispatchedOrderCredits();
    }

    public function down(): void
    {
        // Historical credits cannot be un-posted safely.
    }
};
