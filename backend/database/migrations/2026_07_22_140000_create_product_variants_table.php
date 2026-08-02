<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('variant_code', 40);
            $table->decimal('pack_size', 14, 4);
            $table->string('pack_unit', 30);
            $table->string('packaging_type', 50)->nullable();
            $table->unsignedInteger('units_per_case')->nullable();
            $table->decimal('net_weight', 14, 4)->nullable();
            $table->boolean('manufacturing_enabled')->default(true);
            $table->boolean('is_bulk')->default(false);
            $table->boolean('status')->default(true);
            $table->decimal('current_stock', 14, 3)->default(0);
            $table->string('stock_unit', 30)->default('Nos');
            $table->decimal('average_production_cost', 14, 4)->default(0);
            $table->decimal('stock_value', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'variant_code']);
            $table->index(['product_id', 'status']);
            $table->index(['product_id', 'manufacturing_enabled', 'status']);
        });

        // Backfill a default pack variant for manufacturable products so existing BOMs remain usable.
        $products = DB::table('products')
            ->whereNull('deleted_at')
            ->where(function ($query): void {
                $query->where('manufacturing_enabled', true)
                    ->orWhereNotNull('pack_size');
            })
            ->get(['id', 'product_code', 'pack_size', 'uom', 'nos_per_case', 'production_unit', 'current_finished_stock', 'weighted_average_cost']);

        foreach ($products as $product) {
            $packSize = $this->parsePackSize($product->pack_size);
            $packUnit = $product->production_unit ?: ($product->uom ?: 'Nos');
            $isBulk = $packSize <= 0;
            if ($isBulk) {
                $packSize = 1;
            }

            $variantId = DB::table('product_variants')->insertGetId([
                'product_id' => $product->id,
                'variant_code' => $product->product_code.'-P01',
                'pack_size' => $packSize,
                'pack_unit' => $packUnit,
                'packaging_type' => $isBulk ? 'Bulk' : null,
                'units_per_case' => $product->nos_per_case ?: null,
                'net_weight' => null,
                'manufacturing_enabled' => true,
                'is_bulk' => $isBulk,
                'status' => true,
                'current_stock' => (float) $product->current_finished_stock,
                'stock_unit' => 'Nos',
                'average_production_cost' => (float) $product->weighted_average_cost,
                'stock_value' => round((float) $product->current_finished_stock * (float) $product->weighted_average_cost, 2),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Stash for next migrations via temporary lookup table pattern (product_id => variant_id).
            DB::table('product_variants')
                ->where('id', $variantId)
                ->update(['updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }

    private function parsePackSize(?string $packSize): float
    {
        if ($packSize === null || trim($packSize) === '') {
            return 0;
        }

        if (preg_match('/(\d+(?:\.\d+)?)/', $packSize, $matches) === 1) {
            return (float) $matches[1];
        }

        return 0;
    }
};
