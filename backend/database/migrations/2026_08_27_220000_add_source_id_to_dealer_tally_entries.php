<?php

use App\Services\Dealers\DealerLedgerPostingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dealer_tally_entries')) {
            return;
        }

        Schema::table('dealer_tally_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('dealer_tally_entries', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable()->after('source');
                $table->index(['source', 'source_id']);
            }
        });

        app(DealerLedgerPostingService::class)->backfill();
    }

    public function down(): void
    {
        if (! Schema::hasTable('dealer_tally_entries') || ! Schema::hasColumn('dealer_tally_entries', 'source_id')) {
            return;
        }

        Schema::table('dealer_tally_entries', function (Blueprint $table): void {
            $table->dropIndex(['source', 'source_id']);
            $table->dropColumn('source_id');
        });
    }
};
