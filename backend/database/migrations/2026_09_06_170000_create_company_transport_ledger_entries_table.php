<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_transport_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->date('transaction_date');
            $table->string('entry_kind', 20);
            $table->string('source', 40);
            $table->string('particulars', 255);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('order_no', 50)->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('vehicle_number', 50)->nullable();
            $table->string('expense_type', 50)->nullable();
            $table->string('paid_to', 255)->nullable();
            $table->string('payment_mode', 30)->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('debit_amount', 14, 2)->default(0);
            $table->decimal('credit_amount', 14, 2)->default(0);
            $table->text('remark')->nullable();
            $table->string('attachment_path', 500)->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entered_by_role', 80)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_entry_id')->nullable()->constrained('company_transport_ledger_entries')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['transaction_date', 'id']);
            $table->index(['entry_kind', 'reversed_at']);
            $table->index('order_id');
            $table->index('vehicle_number');
            $table->index('expense_type');
            $table->index('entered_by');
        });

        Schema::create('company_transport_ledger_entry_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('company_transport_ledger_entries')->cascadeOnDelete();
            $table->string('action', 30);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 80)->nullable();
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->index(['entry_id', 'acted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_transport_ledger_entry_audits');
        Schema::dropIfExists('company_transport_ledger_entries');
    }
};
