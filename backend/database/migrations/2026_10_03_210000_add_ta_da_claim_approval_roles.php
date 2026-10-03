<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ta_da_claims', function (Blueprint $table) {
            $table->string('submitter_role', 32)->default('employee')->after('employee_id');
            $table->string('approver_role', 32)->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('ta_da_claims', function (Blueprint $table) {
            $table->dropColumn(['submitter_role', 'approver_role']);
        });
    }
};
