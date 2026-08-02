<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packaging_materials', function (Blueprint $table) {
            $table->id();
            $table->string('packaging_code')->unique();
            $table->string('packaging_name');
            $table->string('category')->default('Other');
            $table->string('unit', 30);
            $table->decimal('opening_stock', 14, 3)->default(0);
            $table->decimal('current_stock', 14, 3)->default(0);
            $table->decimal('minimum_stock', 14, 3)->default(0);
            $table->decimal('purchase_rate', 14, 4)->default(0);
            $table->decimal('average_rate', 14, 4)->default(0);
            $table->decimal('current_stock_value', 14, 2)->default(0);
            $table->boolean('status')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category', 'status']);
            $table->index('packaging_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_materials');
    }
};
