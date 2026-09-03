<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_items') || ! Schema::hasColumn('order_items', 'rate_type')) {
            return;
        }

        $ids = DB::table('order_items')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('order_items.rate_type', 'price_list')
            ->whereRaw('ABS(order_items.rate_per_no - products.dealer_price) >= 0.001')
            ->select('order_items.id as id')
            ->pluck('id');

        foreach ($ids->chunk(200) as $chunk) {
            DB::table('order_items')
                ->whereIn('id', $chunk->all())
                ->update(['rate_type' => 'fixed_rate']);
        }
    }

    public function down(): void
    {
        // Irreversible: original price_list rows that stored a custom rate
        // cannot be distinguished from true Fixed Rate after backfill.
    }
};
