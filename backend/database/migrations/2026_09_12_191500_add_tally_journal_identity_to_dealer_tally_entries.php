<?php

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
            if (! Schema::hasColumn('dealer_tally_entries', 'tally_voucher_guid')) {
                $table->string('tally_voucher_guid', 80)->nullable()->after('tally_reconciled_at');
            }
            if (! Schema::hasColumn('dealer_tally_entries', 'tally_master_id')) {
                $table->string('tally_master_id', 100)->nullable()->after('tally_voucher_guid');
            }
            if (! Schema::hasColumn('dealer_tally_entries', 'tally_entry_key')) {
                $table->string('tally_entry_key', 80)->nullable()->after('tally_master_id');
            }
        });

        Schema::table('dealer_tally_entries', function (Blueprint $table): void {
            $table->index('tally_voucher_guid');
            $table->unique(['tally_voucher_guid', 'tally_entry_key'], 'dealer_tally_entries_journal_identity');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dealer_tally_entries')) {
            return;
        }

        Schema::table('dealer_tally_entries', function (Blueprint $table): void {
            $table->dropUnique('dealer_tally_entries_journal_identity');
            $table->dropIndex(['tally_voucher_guid']);
            foreach (['tally_entry_key', 'tally_master_id', 'tally_voucher_guid'] as $column) {
                if (Schema::hasColumn('dealer_tally_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
