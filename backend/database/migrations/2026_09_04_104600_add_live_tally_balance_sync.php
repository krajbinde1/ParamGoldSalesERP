<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dealer_tally_ledgers')) {
            Schema::table('dealer_tally_ledgers', function (Blueprint $table): void {
                if (! Schema::hasColumn('dealer_tally_ledgers', 'live_closing_balance')) {
                    $table->decimal('live_closing_balance', 14, 2)->nullable()->after('last_imported_at');
                }
                if (! Schema::hasColumn('dealer_tally_ledgers', 'live_closing_balance_type')) {
                    $table->string('live_closing_balance_type', 10)->nullable()->after('live_closing_balance');
                }
                if (! Schema::hasColumn('dealer_tally_ledgers', 'live_tally_ledger_name')) {
                    $table->string('live_tally_ledger_name')->nullable()->after('live_closing_balance_type');
                }
                if (! Schema::hasColumn('dealer_tally_ledgers', 'live_synced_at')) {
                    $table->timestamp('live_synced_at')->nullable()->after('live_tally_ledger_name');
                }
            });
        }

        if (! Schema::hasTable('tally_live_sync_states')) {
            Schema::create('tally_live_sync_states', function (Blueprint $table): void {
                $table->id();
                $table->string('connector_id', 100)->nullable();
                $table->boolean('tally_online')->default(false);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('last_tally_online_at')->nullable();
                $table->timestamp('last_balance_sync_at')->nullable();
                $table->timestamp('sync_requested_at')->nullable();
                $table->unsignedInteger('last_matched_count')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dealer_tally_ledgers')) {
            Schema::table('dealer_tally_ledgers', function (Blueprint $table): void {
                foreach (['live_closing_balance', 'live_closing_balance_type', 'live_tally_ledger_name', 'live_synced_at'] as $column) {
                    if (Schema::hasColumn('dealer_tally_ledgers', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('tally_live_sync_states');
    }
};
