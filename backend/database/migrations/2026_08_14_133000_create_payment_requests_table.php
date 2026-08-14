<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_requests')) {
            Schema::create('payment_requests', function (Blueprint $table): void {
                $table->id();
                $table->string('request_no', 32)->unique();
                $table->string('vendor_name');
                $table->string('vendor_mobile', 20);
                $table->decimal('amount', 12, 2);
                $table->text('remark')->nullable();
                $table->string('status', 40);

                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

                $table->foreignId('first_approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('first_approver_name')->nullable();
                $table->string('first_approver_role')->nullable();
                $table->timestamp('first_approved_at')->nullable();
                $table->text('first_rejection_remark')->nullable();

                $table->foreignId('second_approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('second_approver_name')->nullable();
                $table->string('second_approver_role')->nullable();
                $table->timestamp('second_approved_at')->nullable();
                $table->text('second_rejection_remark')->nullable();

                $table->foreignId('payment_done_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('payment_done_at')->nullable();
                $table->text('payment_remark')->nullable();
                $table->string('payment_proof_path')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->index('status');
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('payment_request_push_dedupe')) {
            Schema::create('payment_request_push_dedupe', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type', 64);
                $table->string('status_key', 64);
                $table->timestamps();

                $table->unique(
                    ['payment_request_id', 'user_id', 'type', 'status_key'],
                    'pr_push_dedupe_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_request_push_dedupe');
        Schema::dropIfExists('payment_requests');
    }
};
