<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledgers', function (Blueprint $table) {
            $table->id();
            $table->date('transaction_date');
            $table->string('transaction_type', 40);
            $table->string('item_type', 30);
            $table->foreignId('raw_material_id')->nullable()->constrained('raw_materials')->nullOnDelete();
            $table->foreignId('packaging_material_id')->nullable()->constrained('packaging_materials')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('batch_number')->nullable();
            $table->decimal('quantity_in', 14, 3)->default(0);
            $table->decimal('quantity_out', 14, 3)->default(0);
            $table->decimal('stock_before', 14, 3)->default(0);
            $table->decimal('stock_after', 14, 3)->default(0);
            $table->decimal('rate', 14, 4)->default(0);
            $table->decimal('transaction_value', 14, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['transaction_date', 'transaction_type'], 'sl_date_type_idx');
            $table->index(['item_type', 'raw_material_id'], 'sl_item_raw_idx');
            $table->index(['item_type', 'packaging_material_id'], 'sl_item_pack_idx');
            $table->index(['item_type', 'product_id'], 'sl_item_product_idx');
            $table->index(['reference_type', 'reference_id'], 'sl_reference_idx');
            $table->index('batch_number', 'sl_batch_number_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledgers');
    }
};
