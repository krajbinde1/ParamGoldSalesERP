<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained('boms')->cascadeOnDelete();
            $table->string('item_type', 30);
            $table->foreignId('raw_material_id')->nullable()->constrained('raw_materials')->nullOnDelete();
            $table->foreignId('packaging_material_id')->nullable()->constrained('packaging_materials')->nullOnDelete();
            $table->decimal('required_quantity', 14, 4);
            $table->string('unit', 30);
            $table->decimal('wastage_percentage', 8, 3)->default(0);
            $table->decimal('calculated_quantity', 14, 4);
            $table->boolean('is_optional')->default(false);
            $table->text('remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['bom_id', 'item_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_items');
    }
};
