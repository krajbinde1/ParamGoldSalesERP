<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('boms')) {
            Schema::table('boms', function (Blueprint $table) {
                try {
                    $table->dropUnique('boms_product_variant_version_unique');
                } catch (\Throwable) {
                }
                try {
                    $table->dropIndex('boms_product_variant_status_idx');
                } catch (\Throwable) {
                }
            });

            Schema::table('boms', function (Blueprint $table) {
                if (Schema::hasColumn('boms', 'product_variant_id')) {
                    try {
                        $table->dropForeign(['product_variant_id']);
                    } catch (\Throwable) {
                    }
                    $table->dropColumn('product_variant_id');
                }

                $drop = array_values(array_filter([
                    Schema::hasColumn('boms', 'calculated_packaging_quantity') ? 'calculated_packaging_quantity' : null,
                    Schema::hasColumn('boms', 'actual_packaging_quantity') ? 'actual_packaging_quantity' : null,
                    Schema::hasColumn('boms', 'packaging_quantity_overridden') ? 'packaging_quantity_overridden' : null,
                ]));

                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });

            Schema::table('boms', function (Blueprint $table) {
                try {
                    $table->unique(['product_id', 'bom_version']);
                } catch (\Throwable) {
                }
            });
        }

        if (Schema::hasTable('production_batches') && Schema::hasColumn('production_batches', 'product_variant_id')) {
            Schema::table('production_batches', function (Blueprint $table) {
                try {
                    $table->dropIndex('pb_product_variant_idx');
                } catch (\Throwable) {
                }
                try {
                    $table->dropForeign(['product_variant_id']);
                } catch (\Throwable) {
                }
                $table->dropColumn('product_variant_id');
            });
        }

        if (Schema::hasTable('stock_ledgers') && Schema::hasColumn('stock_ledgers', 'product_variant_id')) {
            Schema::table('stock_ledgers', function (Blueprint $table) {
                try {
                    $table->dropIndex('sl_item_product_variant_idx');
                } catch (\Throwable) {
                }
                try {
                    $table->dropForeign(['product_variant_id']);
                } catch (\Throwable) {
                }
                $table->dropColumn('product_variant_id');
            });
        }
    }

    public function down(): void
    {
        // Irreversible cleanup of unfinished pack-variant BOM feature.
    }
};
