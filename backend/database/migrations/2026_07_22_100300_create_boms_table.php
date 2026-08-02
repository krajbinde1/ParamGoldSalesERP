<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->string('bom_number')->unique();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('bom_version', 30);
            $table->decimal('standard_batch_size', 14, 3);
            $table->decimal('output_quantity', 14, 3);
            $table->date('effective_date');
            $table->string('status', 20)->default('active');
            $table->decimal('wastage_percentage', 8, 3)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'bom_version']);
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boms');
    }
};
