<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ta_da_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('claim_date');
            $table->string('from_location');
            $table->string('to_location');
            $table->decimal('travel_km', 10, 2);
            $table->decimal('per_km_rate', 10, 2);
            $table->decimal('travel_amount', 10, 2);
            $table->decimal('other_expense', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);
            $table->string('bill_photo_path');
            $table->text('employee_remarks')->nullable();
            $table->text('admin_remark')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ta_da_claims');
    }
};
