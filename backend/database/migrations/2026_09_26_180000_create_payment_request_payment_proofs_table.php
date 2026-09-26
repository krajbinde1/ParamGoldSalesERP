<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_request_payment_proofs')) {
            return;
        }

        Schema::create('payment_request_payment_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_request_id')
                ->constrained('payment_requests')
                ->cascadeOnDelete();
            $table->string('original_file_name');
            $table->string('stored_file_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payment_request_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_request_payment_proofs');
    }
};
