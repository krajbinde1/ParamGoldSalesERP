<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tally_dealer_mappings') && ! Schema::hasColumn('tally_dealer_mappings', 'tally_ledger_guid')) {
            Schema::table('tally_dealer_mappings', function (Blueprint $table): void {
                $table->string('tally_ledger_guid', 80)->nullable()->after('tally_ledger_name_normalized');
            });
            Schema::table('tally_dealer_mappings', function (Blueprint $table): void {
                $table->unique('tally_ledger_guid');
            });
        }

        if (Schema::hasTable('dealer_tally_ledgers') && ! Schema::hasColumn('dealer_tally_ledgers', 'live_tally_ledger_guid')) {
            Schema::table('dealer_tally_ledgers', function (Blueprint $table): void {
                $table->string('live_tally_ledger_guid', 80)->nullable()->after('live_tally_ledger_name');
            });
        }

        if (! Schema::hasTable('tally_connector_ledgers')) {
            Schema::create('tally_connector_ledgers', function (Blueprint $table): void {
                $table->id();
                $table->string('tally_ledger_guid', 80)->nullable()->unique();
                $table->string('tally_ledger_name');
                $table->string('tally_ledger_name_normalized');
                $table->string('ledger_parent')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->index('tally_ledger_name_normalized');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tally_connector_ledgers');

        if (Schema::hasTable('dealer_tally_ledgers') && Schema::hasColumn('dealer_tally_ledgers', 'live_tally_ledger_guid')) {
            Schema::table('dealer_tally_ledgers', function (Blueprint $table): void {
                $table->dropColumn('live_tally_ledger_guid');
            });
        }

        if (Schema::hasTable('tally_dealer_mappings') && Schema::hasColumn('tally_dealer_mappings', 'tally_ledger_guid')) {
            Schema::table('tally_dealer_mappings', function (Blueprint $table): void {
                $table->dropUnique(['tally_ledger_guid']);
                $table->dropColumn('tally_ledger_guid');
            });
        }
    }
};
