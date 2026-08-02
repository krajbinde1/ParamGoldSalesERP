<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_variants')
                ->nullOnDelete();
            $table->decimal('finished_packs_produced', 14, 3)->nullable()->after('actual_output_quantity');
            $table->decimal('cost_per_pack', 14, 4)->default(0)->after('cost_per_unit');
            $table->decimal('cost_per_case', 14, 4)->nullable()->after('cost_per_pack');
        });

        $batches = DB::table('production_batches')->get(['id', 'product_id', 'bom_id']);
        foreach ($batches as $batch) {
            $variantId = DB::table('boms')->where('id', $batch->bom_id)->value('product_variant_id');
            if ($variantId === null) {
                $variantId = DB::table('product_variants')
                    ->where('product_id', $batch->product_id)
                    ->orderBy('id')
                    ->value('id');
            }

            if ($variantId !== null) {
                DB::table('production_batches')->where('id', $batch->id)->update([
                    'product_variant_id' => $variantId,
                ]);
            }
        }

        Schema::table('production_batches', function (Blueprint $table) {
            $table->index(['product_id', 'product_variant_id'], 'pb_product_variant_idx');
        });
    }

    public function down(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->dropIndex('pb_product_variant_idx');
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn(['finished_packs_produced', 'cost_per_pack', 'cost_per_case']);
        });
    }
};
