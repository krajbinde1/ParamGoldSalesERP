<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tally_outbound_vouchers')) {
            return;
        }

        Schema::create('tally_outbound_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->string('voucher_type', 30);
            $table->string('erp_reference');
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->string('claimed_by')->nullable();
            $table->string('tally_voucher_no')->nullable();
            $table->string('tally_master_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique('erp_reference');
            $table->unique(['source_type', 'source_id']);
            $table->index('status');
            $table->index(['status', 'claimed_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tally_outbound_vouchers');
    }
};
