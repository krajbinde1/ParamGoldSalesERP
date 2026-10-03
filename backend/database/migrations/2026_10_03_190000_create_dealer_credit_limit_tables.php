<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealer_credit_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dealer_id')->unique()->constrained('dealers')->cascadeOnDelete();
            $table->decimal('base_limit', 14, 2)->nullable();
            $table->decimal('extension_amount', 14, 2)->default(0);
            $table->date('extension_valid_until')->nullable();
            $table->timestamp('extension_expired_at')->nullable();
            $table->text('extension_remark')->nullable();
            $table->text('remark')->nullable();
            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('set_by_role', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('dealer_credit_limit_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dealer_id')->constrained('dealers')->cascadeOnDelete();
            $table->string('action', 32);
            $table->decimal('previous_base', 14, 2)->nullable();
            $table->decimal('new_base', 14, 2)->nullable();
            $table->decimal('extension_amount', 14, 2)->nullable();
            $table->decimal('effective_limit', 14, 2)->nullable();
            $table->date('valid_until')->nullable();
            $table->text('remark')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_role', 32);
            $table->timestamps();

            $table->index(['dealer_id', 'id']);
        });

        Schema::create('dealer_credit_warning_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dealer_id')->constrained('dealers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 40);
            $table->timestamps();

            $table->unique(['dealer_id', 'user_id']);
        });

        Schema::create('dealer_credit_order_locks', function (Blueprint $table): void {
            $table->unsignedBigInteger('dealer_id')->primary();
            $table->string('owner', 64);
            $table->timestamp('locked_until');
            $table->timestamps();

            $table->foreign('dealer_id')->references('id')->on('dealers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealer_credit_order_locks');
        Schema::dropIfExists('dealer_credit_warning_notices');
        Schema::dropIfExists('dealer_credit_limit_audits');
        Schema::dropIfExists('dealer_credit_limits');
    }
};
