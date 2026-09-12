<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tally_live_sync_states')) {
            return;
        }

        Schema::table('tally_live_sync_states', function (Blueprint $table): void {
            if (! Schema::hasColumn('tally_live_sync_states', 'last_heartbeat_at')) {
                $table->timestamp('last_heartbeat_at')->nullable()->after('last_seen_at');
            }
            if (! Schema::hasColumn('tally_live_sync_states', 'tally_company')) {
                $table->string('tally_company')->nullable()->after('connector_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tally_live_sync_states')) {
            return;
        }

        Schema::table('tally_live_sync_states', function (Blueprint $table): void {
            foreach (['last_heartbeat_at', 'tally_company'] as $column) {
                if (Schema::hasColumn('tally_live_sync_states', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
