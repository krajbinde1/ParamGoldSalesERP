<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_material_inward_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('raw_material_inward_id');
            $table->unsignedBigInteger('raw_material_id');
            $table->string('material_code')->nullable();
            $table->string('material_name')->nullable();
            $table->string('supplier_batch_number')->nullable();
            $table->string('internal_batch_number')->nullable();
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('received_quantity', 14, 3)->default(0);
            $table->decimal('accepted_quantity', 14, 3)->default(0);
            $table->decimal('rejected_quantity', 14, 3)->default(0);
            $table->decimal('free_quantity', 14, 3)->default(0);
            $table->string('unit', 30)->nullable();
            $table->decimal('basic_rate', 14, 4)->default(0);
            $table->decimal('discount_percentage', 8, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('freight_amount', 14, 2)->default(0);
            $table->decimal('loading_unloading_amount', 14, 2)->default(0);
            $table->decimal('other_charges', 14, 2)->default(0);
            $table->decimal('taxable_amount', 14, 2)->default(0);
            $table->decimal('gst_percentage', 8, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('landed_cost', 14, 2)->default(0);
            $table->decimal('effective_unit_rate', 14, 4)->default(0);
            $table->decimal('old_average_rate', 14, 4)->nullable();
            $table->decimal('new_average_rate', 14, 4)->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('raw_material_inward_id', 'rmi_items_inward_fk')
                ->references('id')->on('raw_material_inwards')->cascadeOnDelete();
            $table->foreign('raw_material_id', 'rmi_items_material_fk')
                ->references('id')->on('raw_materials')->restrictOnDelete();
            $table->index('raw_material_id', 'rmi_items_material_idx');
            $table->index('internal_batch_number', 'rmi_items_batch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_material_inward_items');
    }
};
