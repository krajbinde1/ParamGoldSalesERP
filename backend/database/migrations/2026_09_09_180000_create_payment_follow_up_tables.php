<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_follow_up_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dealer_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->unsignedInteger('cycle_number');
            $table->decimal('opening_outstanding', 15, 2);
            $table->timestamp('started_at');
            $table->string('status', 20)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('payment_received_amount', 15, 2)->nullable();
            $table->decimal('closing_outstanding', 15, 2)->nullable();
            $table->foreignId('collection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['dealer_id', 'cycle_number']);
            $table->index(['dealer_id', 'status']);
            $table->index(['employee_id', 'status']);
        });

        Schema::create('payment_follow_up_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained('payment_follow_up_cycles')->restrictOnDelete();
            $table->foreignId('dealer_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entry_type', 30)->default('follow_up');
            $table->timestamp('followed_up_at');
            $table->text('remark');
            $table->decimal('outstanding_at_time', 15, 2);
            $table->decimal('expected_amount', 15, 2)->nullable();
            $table->date('next_follow_up_date')->nullable();
            $table->string('employee_notification_status', 20)->default('pending');
            $table->timestamp('employee_notification_sent_at')->nullable();
            $table->text('employee_notification_error')->nullable();
            $table->string('whatsapp_status', 20)->default('pending');
            $table->timestamp('whatsapp_sent_at')->nullable();
            $table->text('whatsapp_error')->nullable();
            $table->unsignedBigInteger('whatsapp_outbound_message_id')->nullable();
            $table->foreignId('collection_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['cycle_id', 'id']);
            $table->index(['dealer_id', 'followed_up_at']);
            $table->index(['next_follow_up_date', 'whatsapp_status']);
            $table->index(['next_follow_up_date', 'employee_notification_status']);
            $table->index('entry_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_follow_up_entries');
        Schema::dropIfExists('payment_follow_up_cycles');
    }
};
