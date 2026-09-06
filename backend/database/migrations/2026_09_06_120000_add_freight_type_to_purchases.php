<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('freight_type', 40)->default('total_freight')->after('grand_total');
            $table->decimal('freight_rate_per_ton', 14, 4)->nullable()->after('freight_type');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['freight_type', 'freight_rate_per_ton']);
        });
    }
};
