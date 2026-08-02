<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('semi_finished_materials', function (Blueprint $table) {
            if (! Schema::hasColumn('semi_finished_materials', 'opening_stock')) {
                $table->decimal('opening_stock', 14, 3)->default(0)->after('unit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('semi_finished_materials', function (Blueprint $table) {
            if (Schema::hasColumn('semi_finished_materials', 'opening_stock')) {
                $table->dropColumn('opening_stock');
            }
        });
    }
};
