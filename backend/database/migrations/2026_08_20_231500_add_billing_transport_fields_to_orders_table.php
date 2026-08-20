<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('transport_charge_type', 30)->nullable()->after('transport_amount');
            $table->decimal('original_grand_total', 14, 2)->nullable()->after('transport_charge_type');
            $table->decimal('transport_adjustment', 14, 2)->nullable()->after('original_grand_total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'transport_charge_type',
                'original_grand_total',
                'transport_adjustment',
            ]);
        });
    }
};
