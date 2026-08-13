<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('note')->nullable();
            $table->date('due_date');
            $table->time('due_time')->nullable();
            $table->boolean('is_important')->default(false);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reminder_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'due_date']);
            $table->index(['employee_id', 'is_completed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_tasks');
    }
};
