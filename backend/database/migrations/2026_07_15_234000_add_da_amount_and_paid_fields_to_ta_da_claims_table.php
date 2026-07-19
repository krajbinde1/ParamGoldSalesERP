<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ta_da_claims', function (Blueprint $table) {
            $table->decimal('da_amount', 10, 2)->default(0)->after('travel_amount');
            $table->foreignId('paid_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('paid_by');
        });
    }

    public function down(): void
    {
        Schema::table('ta_da_claims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn(['da_amount', 'paid_at']);
        });
    }
};
