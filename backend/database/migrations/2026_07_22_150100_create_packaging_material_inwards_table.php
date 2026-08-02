<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packaging_material_inwards', function (Blueprint $table) {
            $table->id();
            $table->string('inward_number')->unique();
            $table->date('inward_date');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('supplier_invoice_number');
            $table->date('supplier_invoice_date')->nullable();
            $table->text('remarks')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('status', 40)->default('draft');
            $table->decimal('total_basic_value', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->decimal('total_freight', 14, 2)->default(0);
            $table->decimal('total_other_charges', 14, 2)->default(0);
            $table->decimal('total_taxable_value', 14, 2)->default(0);
            $table->decimal('total_gst', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->decimal('total_accepted_qty', 14, 3)->default(0);
            $table->decimal('total_rejected_qty', 14, 3)->default(0);
            $table->unsignedInteger('total_items')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('inward_date');
            $table->index('status');
            $table->index('supplier_invoice_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_material_inwards');
    }
};
