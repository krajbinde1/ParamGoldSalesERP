<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_material_inward_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number')->unique();
            $table->unsignedBigInteger('raw_material_inward_id');
            $table->unsignedBigInteger('raw_material_inward_item_id')->nullable();
            $table->unsignedBigInteger('raw_material_id');
            $table->unsignedBigInteger('raw_material_batch_id')->nullable();
            $table->date('return_date');
            $table->decimal('return_quantity', 14, 3);
            $table->string('reason');
            $table->string('supplier_credit_note_number')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 30)->default('draft');
            $table->decimal('return_rate', 14, 4)->default(0);
            $table->decimal('return_value', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->foreign('raw_material_inward_id', 'rmi_ret_inward_fk')
                ->references('id')->on('raw_material_inwards')->restrictOnDelete();
            $table->foreign('raw_material_inward_item_id', 'rmi_ret_item_fk')
                ->references('id')->on('raw_material_inward_items')->nullOnDelete();
            $table->foreign('raw_material_id', 'rmi_ret_material_fk')
                ->references('id')->on('raw_materials')->restrictOnDelete();
            $table->foreign('raw_material_batch_id', 'rmi_ret_batch_fk')
                ->references('id')->on('raw_material_batches')->nullOnDelete();
            $table->index('return_date', 'rmi_returns_date_idx');
            $table->index('status', 'rmi_returns_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_material_inward_returns');
    }
};
