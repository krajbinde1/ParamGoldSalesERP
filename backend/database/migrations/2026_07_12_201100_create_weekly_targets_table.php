<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('week_start_date');
            $table->date('week_end_date');
            $table->decimal('sales_target', 15, 2)->default(0);
            $table->decimal('collection_target', 15, 2)->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['employee_id', 'week_start_date']);
            $table->index(['status', 'week_start_date', 'week_end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_targets');
    }
};
