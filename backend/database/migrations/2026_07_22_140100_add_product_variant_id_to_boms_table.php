<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_variants')
                ->nullOnDelete();
            $table->decimal('calculated_packaging_quantity', 14, 4)->nullable()->after('output_quantity');
            $table->decimal('actual_packaging_quantity', 14, 4)->nullable()->after('calculated_packaging_quantity');
            $table->boolean('packaging_quantity_overridden')->default(false)->after('actual_packaging_quantity');
        });

        // Attach each existing BOM to the first active variant of its product.
        $boms = DB::table('boms')->get(['id', 'product_id']);
        foreach ($boms as $bom) {
            $variantId = DB::table('product_variants')
                ->where('product_id', $bom->product_id)
                ->where('status', true)
                ->orderBy('id')
                ->value('id');

            if ($variantId !== null) {
                DB::table('boms')->where('id', $bom->id)->update([
                    'product_variant_id' => $variantId,
                ]);
            }
        }

        Schema::table('boms', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'bom_version']);
            $table->unique(['product_id', 'product_variant_id', 'bom_version'], 'boms_product_variant_version_unique');
            $table->index(['product_id', 'product_variant_id', 'status'], 'boms_product_variant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            $table->dropUnique('boms_product_variant_version_unique');
            $table->dropIndex('boms_product_variant_status_idx');
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn([
                'calculated_packaging_quantity',
                'actual_packaging_quantity',
                'packaging_quantity_overridden',
            ]);
            $table->unique(['product_id', 'bom_version']);
        });
    }
};
