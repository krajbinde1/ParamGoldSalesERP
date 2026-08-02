<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number')->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('bom_id')->constrained('boms')->restrictOnDelete();
            $table->string('bom_version', 30);
            $table->date('production_date');
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('planned_quantity', 14, 3);
            $table->decimal('actual_output_quantity', 14, 3)->default(0);
            $table->decimal('wastage_quantity', 14, 3)->default(0);
            $table->decimal('total_material_cost', 14, 2)->default(0);
            $table->decimal('total_packaging_cost', 14, 2)->default(0);
            $table->decimal('labour_cost', 14, 2)->default(0);
            $table->decimal('electricity_cost', 14, 2)->default(0);
            $table->decimal('machine_cost', 14, 2)->default(0);
            $table->decimal('processing_cost', 14, 2)->default(0);
            $table->decimal('transport_cost', 14, 2)->default(0);
            $table->decimal('other_manufacturing_cost', 14, 2)->default(0);
            $table->decimal('total_conversion_cost', 14, 2)->default(0);
            $table->decimal('total_batch_cost', 14, 2)->default(0);
            $table->decimal('cost_per_unit', 14, 4)->default(0);
            $table->decimal('material_cost_per_unit', 14, 4)->default(0);
            $table->decimal('packaging_cost_per_unit', 14, 4)->default(0);
            $table->decimal('conversion_cost_per_unit', 14, 4)->default(0);
            $table->string('status', 30)->default('draft');
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('posting_token', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['production_date', 'status']);
            $table->index('supervisor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batches');
    }
};
