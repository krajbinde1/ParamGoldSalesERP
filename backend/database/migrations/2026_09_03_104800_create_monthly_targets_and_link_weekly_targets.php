<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('month_start_date');
            $table->decimal('sales_target', 15, 2)->default(0);
            $table->decimal('collection_target', 15, 2)->default(0);
            $table->unsignedInteger('field_activity_target')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'month_start_date']);
            $table->index(['status', 'month_start_date']);
        });

        Schema::table('weekly_targets', function (Blueprint $table) {
            $table->foreignId('monthly_target_id')
                ->nullable()
                ->after('employee_id')
                ->constrained('monthly_targets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('weekly_targets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('monthly_target_id');
        });

        Schema::dropIfExists('monthly_targets');
    }
};
