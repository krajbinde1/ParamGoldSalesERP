<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('credit_note_no', 20)->unique();
            $table->string('type', 32);
            $table->foreignId('dealer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('bill_reference', 100)->nullable();
            $table->date('credit_note_date');
            $table->decimal('amount', 12, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->string('supporting_document_path')->nullable();
            $table->string('status', 32)->default('pending_approval')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_remark')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejected_by_role', 50)->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_remark')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_remark')->nullable();
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_edited_at')->nullable();
            $table->string('last_edited_by_role', 50)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sales_employee_id', 'status']);
            $table->index(['dealer_id', 'credit_note_date']);
        });

        Schema::create('credit_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('rate', 12, 2)->nullable();
            $table->decimal('original_rate', 12, 2)->nullable();
            $table->decimal('revised_rate', 12, 2)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_items');
        Schema::dropIfExists('credit_notes');
    }
};
