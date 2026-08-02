<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom_item_alternates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_item_id')->constrained('bom_items')->cascadeOnDelete();
            $table->string('item_type', 30);
            $table->foreignId('raw_material_id')->nullable()->constrained('raw_materials')->nullOnDelete();
            $table->foreignId('packaging_material_id')->nullable()->constrained('packaging_materials')->nullOnDelete();
            $table->decimal('conversion_ratio', 12, 6)->default(1);
            $table->boolean('is_approved')->default(true);
            $table->unsignedInteger('priority')->default(1);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['bom_item_id', 'is_approved'], 'bia_item_approved_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_item_alternates');
    }
};
