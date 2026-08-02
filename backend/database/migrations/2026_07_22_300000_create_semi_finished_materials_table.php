<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semi_finished_materials', function (Blueprint $table) {
            $table->id();
            $table->string('material_code')->unique();
            $table->string('material_name');
            $table->string('unit', 30);
            $table->decimal('current_stock', 14, 3)->default(0);
            $table->decimal('minimum_stock', 14, 3)->default(0);
            $table->decimal('average_production_cost', 14, 4)->default(0);
            $table->decimal('current_stock_value', 14, 2)->default(0);
            $table->boolean('status')->default(true);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('material_name');
            $table->index(['status', 'current_stock']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('semi_finished_materials');
    }
};
