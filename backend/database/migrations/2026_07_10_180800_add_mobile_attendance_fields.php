<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $t) {
            $t->string('punch_in_photo')->nullable();
            $t->string('punch_out_photo')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('rejected_by')->nullable()->constrained('employees')->nullOnDelete();
            $t->timestamp('rejected_at')->nullable();
            $t->unsignedInteger('total_working_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $t) {
            $t->dropForeign(['rejected_by']);
            $t->dropColumn(['punch_in_photo', 'punch_out_photo', 'rejection_reason', 'approved_at', 'rejected_by', 'rejected_at', 'total_working_minutes']);
        });
    }
};
