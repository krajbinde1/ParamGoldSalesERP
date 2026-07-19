<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('transport_type', 30)->nullable()->after('dispatch_remark');
            $table->decimal('transport_amount', 14, 2)->nullable()->after('transport_type');
            $table->decimal('subtotal_before_transport', 14, 2)->nullable()->after('transport_amount');
            $table->decimal('taxable_amount_after_transport', 14, 2)->nullable()->after('subtotal_before_transport');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'transport_type',
                'transport_amount',
                'subtotal_before_transport',
                'taxable_amount_after_transport',
            ]);
        });
    }
};
