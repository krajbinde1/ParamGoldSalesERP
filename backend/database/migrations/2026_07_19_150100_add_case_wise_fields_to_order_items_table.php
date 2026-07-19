<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_items', 'case_quantity')) {
                $table->unsignedInteger('case_quantity')->nullable()->after('product_id');
                $table->unsignedInteger('nos_per_case')->nullable()->after('case_quantity');
                $table->unsignedInteger('total_quantity_nos')->nullable()->after('nos_per_case');
                $table->decimal('rate_per_no', 12, 2)->nullable()->after('total_quantity_nos');
                $table->decimal('base_amount', 14, 2)->nullable()->after('rate');
                $table->decimal('discount_amount', 14, 2)->nullable()->after('discount_percentage');
                $table->decimal('taxable_amount', 14, 2)->nullable()->after('discount_amount');
                $table->decimal('gst_amount', 14, 2)->nullable()->after('gst_percentage');
                $table->decimal('final_amount', 14, 2)->nullable()->after('gst_amount');
            }
        });

        DB::table('order_items')->orderBy('id')->each(function (object $item): void {
            $quantity = (float) $item->quantity;
            $rate = (float) $item->rate;
            $discountPercentage = (float) $item->discount_percentage;
            $gstPercentage = (float) $item->gst_percentage;

            $baseAmount = round($quantity * $rate, 2);
            $discountAmount = round($baseAmount * $discountPercentage / 100, 2);
            $taxableAmount = round($baseAmount - $discountAmount, 2);
            $gstAmount = round($taxableAmount * $gstPercentage / 100, 2);
            $finalAmount = round($taxableAmount + $gstAmount, 2);

            DB::table('order_items')->where('id', $item->id)->update([
                'case_quantity' => 1,
                'nos_per_case' => 1,
                'total_quantity_nos' => (int) round($quantity),
                'rate_per_no' => $rate,
                'base_amount' => $baseAmount,
                'discount_amount' => $discountAmount,
                'taxable_amount' => $taxableAmount,
                'gst_amount' => $gstAmount,
                'final_amount' => $finalAmount,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('order_items', 'case_quantity')) {
                $table->dropColumn([
                    'case_quantity',
                    'nos_per_case',
                    'total_quantity_nos',
                    'rate_per_no',
                    'base_amount',
                    'discount_amount',
                    'taxable_amount',
                    'gst_amount',
                    'final_amount',
                ]);
            }
        });
    }
};
