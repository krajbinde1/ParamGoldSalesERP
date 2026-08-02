<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('manufacturing_enabled')->default(false)->after('status');
            $table->string('production_unit', 30)->nullable()->after('manufacturing_enabled');
            $table->decimal('standard_batch_size', 14, 3)->nullable()->after('production_unit');
            $table->decimal('current_finished_stock', 14, 3)->default(0)->after('standard_batch_size');
            $table->decimal('minimum_finished_stock', 14, 3)->default(0)->after('current_finished_stock');
            $table->decimal('standard_production_cost', 14, 4)->default(0)->after('minimum_finished_stock');
            $table->decimal('latest_production_cost', 14, 4)->default(0)->after('standard_production_cost');
            $table->decimal('weighted_average_cost', 14, 4)->default(0)->after('latest_production_cost');
            $table->unsignedInteger('shelf_life_days')->nullable()->after('weighted_average_cost');
            $table->boolean('batch_tracking_enabled')->default(true)->after('shelf_life_days');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'manufacturing_enabled',
                'production_unit',
                'standard_batch_size',
                'current_finished_stock',
                'minimum_finished_stock',
                'standard_production_cost',
                'latest_production_cost',
                'weighted_average_cost',
                'shelf_life_days',
                'batch_tracking_enabled',
            ]);
        });
    }
};
