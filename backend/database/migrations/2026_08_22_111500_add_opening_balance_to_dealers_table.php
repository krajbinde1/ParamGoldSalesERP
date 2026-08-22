<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealers', function (Blueprint $table) {
            if (! Schema::hasColumn('dealers', 'opening_balance')) {
                $table->decimal('opening_balance', 14, 2)->default(0)->after('outstanding');
            }

            if (! Schema::hasColumn('dealers', 'opening_balance_date')) {
                $table->date('opening_balance_date')->nullable()->after('opening_balance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dealers', function (Blueprint $table) {
            if (Schema::hasColumn('dealers', 'opening_balance_date')) {
                $table->dropColumn('opening_balance_date');
            }

            if (Schema::hasColumn('dealers', 'opening_balance')) {
                $table->dropColumn('opening_balance');
            }
        });
    }
};
