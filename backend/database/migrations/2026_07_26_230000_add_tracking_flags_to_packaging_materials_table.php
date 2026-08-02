<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packaging_materials', function (Blueprint $table) {
            if (! Schema::hasColumn('packaging_materials', 'batch_tracking_enabled')) {
                $table->boolean('batch_tracking_enabled')->default(false)->after('current_stock_value');
            }

            if (! Schema::hasColumn('packaging_materials', 'expiry_tracking_enabled')) {
                $table->boolean('expiry_tracking_enabled')->default(false)->after('batch_tracking_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('packaging_materials', function (Blueprint $table) {
            if (Schema::hasColumn('packaging_materials', 'expiry_tracking_enabled')) {
                $table->dropColumn('expiry_tracking_enabled');
            }

            if (Schema::hasColumn('packaging_materials', 'batch_tracking_enabled')) {
                $table->dropColumn('batch_tracking_enabled');
            }
        });
    }
};
