<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ta_da_claims', function (Blueprint $table) {
            $table->unique(['employee_id', 'claim_date'], 'ta_da_claims_employee_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ta_da_claims', function (Blueprint $table) {
            $table->dropUnique('ta_da_claims_employee_date_unique');
        });
    }
};
