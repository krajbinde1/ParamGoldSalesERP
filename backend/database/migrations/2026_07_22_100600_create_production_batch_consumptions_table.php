<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_batch_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->constrained('production_batches')->cascadeOnDelete();
            $table->string('item_type', 30);
            $table->foreignId('raw_material_id')->nullable()->constrained('raw_materials')->nullOnDelete();
            $table->foreignId('packaging_material_id')->nullable()->constrained('packaging_materials')->nullOnDelete();
            $table->string('material_name');
            $table->string('unit', 30);
            $table->decimal('required_quantity', 14, 4);
            $table->decimal('consumed_quantity', 14, 4);
            $table->decimal('stock_before', 14, 3);
            $table->decimal('stock_after', 14, 3);
            $table->decimal('rate', 14, 4);
            $table->decimal('consumption_value', 14, 2);
            $table->boolean('is_optional')->default(false);
            $table->timestamps();

            $table->index(['production_batch_id', 'item_type'], 'pbc_batch_item_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batch_consumptions');
    }
};
