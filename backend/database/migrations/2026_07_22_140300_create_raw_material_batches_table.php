<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_material_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('raw_material_id');
            $table->string('internal_batch_number');
            $table->string('supplier_batch_number')->nullable();
            $table->unsignedBigInteger('inward_id')->nullable();
            $table->unsignedBigInteger('inward_item_id')->nullable();
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('received_quantity', 14, 3)->default(0);
            $table->decimal('accepted_quantity', 14, 3)->default(0);
            $table->decimal('available_quantity', 14, 3)->default(0);
            $table->decimal('reserved_quantity', 14, 3)->default(0);
            $table->decimal('consumed_quantity', 14, 3)->default(0);
            $table->decimal('returned_quantity', 14, 3)->default(0);
            $table->decimal('effective_unit_rate', 14, 4)->default(0);
            $table->string('status', 30)->default('available');
            $table->timestamps();

            $table->foreign('raw_material_id', 'rm_batches_material_fk')
                ->references('id')->on('raw_materials')->restrictOnDelete();
            $table->foreign('inward_id', 'rm_batches_inward_fk')
                ->references('id')->on('raw_material_inwards')->nullOnDelete();
            $table->foreign('inward_item_id', 'rm_batches_item_fk')
                ->references('id')->on('raw_material_inward_items')->nullOnDelete();
            $table->unique(['raw_material_id', 'internal_batch_number'], 'rm_batches_material_batch_uq');
            $table->index(['raw_material_id', 'status'], 'rm_batches_material_status_idx');
            $table->index('expiry_date', 'rm_batches_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_material_batches');
    }
};
