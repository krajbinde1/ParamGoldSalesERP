<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_number')->unique();
            $table->date('adjustment_date');
            $table->string('adjustment_type', 40);
            $table->string('item_type', 30);
            $table->foreignId('raw_material_id')->nullable()->constrained('raw_materials')->nullOnDelete();
            $table->foreignId('packaging_material_id')->nullable()->constrained('packaging_materials')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('system_stock', 14, 3);
            $table->decimal('adjusted_quantity', 14, 3);
            $table->decimal('stock_after', 14, 3);
            $table->decimal('rate', 14, 4)->default(0);
            $table->decimal('adjustment_value', 14, 2)->default(0);
            $table->string('reason');
            $table->text('remarks')->nullable();
            $table->string('attachment_path')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['adjustment_date', 'adjustment_type'], 'sa_date_type_idx');
            $table->index(['item_type', 'raw_material_id'], 'sa_item_raw_idx');
            $table->index(['item_type', 'packaging_material_id'], 'sa_item_pack_idx');
            $table->index(['item_type', 'product_id'], 'sa_item_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
