<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_number')->unique();
            $table->date('purchase_date');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('supplier_invoice_number')->nullable();
            $table->date('supplier_invoice_date')->nullable();
            $table->string('material_type', 40);
            $table->text('remarks')->nullable();
            $table->string('invoice_path')->nullable();
            $table->string('status', 40)->default('draft');
            $table->decimal('total_quantity', 14, 3)->default(0);
            $table->decimal('total_taxable_amount', 14, 2)->default(0);
            $table->decimal('total_gst', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->unsignedInteger('total_items')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('purchase_date');
            $table->index('status');
            $table->index('material_type');
            $table->index('supplier_invoice_number');
            $table->index(['status', 'purchase_date'], 'purchases_status_date_idx');
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->foreignId('raw_material_id')->nullable()->constrained('raw_materials')->restrictOnDelete();
            $table->foreignId('packaging_material_id')->nullable()->constrained('packaging_materials')->restrictOnDelete();
            $table->string('unit', 30)->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('purchase_rate', 14, 4);
            $table->decimal('taxable_amount', 14, 2)->default(0);
            $table->decimal('gst_percentage', 8, 2)->default(0);
            $table->decimal('gst_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('landed_cost', 14, 2)->default(0);
            $table->decimal('effective_unit_rate', 14, 4)->default(0);
            $table->string('batch_lot_no', 80)->nullable();
            $table->text('remarks')->nullable();
            $table->decimal('stock_before', 14, 3)->nullable();
            $table->decimal('stock_after', 14, 3)->nullable();
            $table->decimal('old_average_rate', 14, 4)->nullable();
            $table->decimal('new_average_rate', 14, 4)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('purchase_id');
            $table->index('raw_material_id');
            $table->index('packaging_material_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
