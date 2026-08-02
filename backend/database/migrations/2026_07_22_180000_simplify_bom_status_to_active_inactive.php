<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep existing active rows; map draft/archived/pending/etc. to inactive.
        DB::table('boms')
            ->whereNotIn('status', ['active', 'inactive'])
            ->update(['status' => 'inactive']);

        // Fresh installs already default to active via create_boms_table.
        // Changing the column default is only needed for databases that already ran the original migration.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('boms', function (Blueprint $table) {
                $table->string('status', 20)->default('active')->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('boms', function (Blueprint $table) {
                $table->string('status', 20)->default('draft')->change();
            });
        }
    }
};
