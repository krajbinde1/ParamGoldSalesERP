<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_freight_ledgers', function (Blueprint $table) {
            $table->id();
            $table->date('transaction_date');
            $table->string('transaction_type', 40);
            $table->foreignId('purchase_id')->constrained('purchases')->restrictOnDelete();
            $table->string('purchase_number');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('transporter_name')->nullable();
            $table->string('transport_invoice_lr_no', 80)->nullable();
            $table->decimal('amount', 14, 2);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('transaction_date');
            $table->index('transaction_type');
            $table->index(['purchase_id', 'transaction_type'], 'tfl_purchase_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_freight_ledgers');
    }
};
