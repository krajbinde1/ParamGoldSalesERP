<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_code')->unique();
            $table->string('product_name');
            $table->string('category');
            $table->string('brand')->nullable();
            $table->string('hsn_code', 8)->nullable();
            $table->string('uom', 30);
            $table->string('pack_size', 50)->nullable();
            $table->decimal('gst_percentage', 5, 2)->default(0);
            $table->decimal('mrp', 12, 2)->default(0);
            $table->decimal('distributor_price', 12, 2)->default(0);
            $table->decimal('dealer_price', 12, 2)->default(0);
            $table->decimal('retail_price', 12, 2)->default(0);
            $table->decimal('minimum_stock', 12, 2)->default(0);
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['category', 'status']);
            $table->index('brand');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
