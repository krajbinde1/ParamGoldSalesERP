<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dealer_tally_entries')) {
            return;
        }

        Schema::table('dealer_tally_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('dealer_tally_entries', 'erp_reference')) {
                $table->string('erp_reference', 40)->nullable()->after('source_id');
            }
        });

        if (Schema::hasColumn('dealer_tally_entries', 'erp_reference')) {
            $concat = Schema::getConnection()->getDriverName() === 'sqlite'
                ? "'ERP-SO-' || source_id"
                : "CONCAT('ERP-SO-', source_id)";

            DB::update(
                "UPDATE dealer_tally_entries
                 SET erp_reference = {$concat}
                 WHERE source = ?
                   AND source_id IS NOT NULL
                   AND (erp_reference IS NULL OR erp_reference = '')",
                ['sales_order'],
            );

            Schema::table('dealer_tally_entries', function (Blueprint $table): void {
                $table->index('erp_reference');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dealer_tally_entries')
            || ! Schema::hasColumn('dealer_tally_entries', 'erp_reference')) {
            return;
        }

        Schema::table('dealer_tally_entries', function (Blueprint $table): void {
            $table->dropIndex(['erp_reference']);
            $table->dropColumn('erp_reference');
        });
    }
};
