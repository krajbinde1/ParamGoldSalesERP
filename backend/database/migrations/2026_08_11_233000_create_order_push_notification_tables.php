<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('device_tokens')) {
            Schema::create('device_tokens', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('token', 512);
                $table->string('platform', 20)->default('android');
                $table->string('device_name')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'token']);
                $table->index('token');
            });
        }

        if (! Schema::hasTable('app_notifications')) {
            Schema::create('app_notifications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
                $table->string('type', 50);
                $table->string('title');
                $table->text('body');
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'read_at']);
                $table->index(['order_id', 'type', 'user_id']);
            });
        }

        if (! Schema::hasTable('order_push_dedupe')) {
            Schema::create('order_push_dedupe', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type', 50);
                $table->string('status_key', 50);
                $table->timestamps();

                $table->unique(['order_id', 'user_id', 'type', 'status_key'], 'order_push_dedupe_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_push_dedupe');
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('device_tokens');
    }
};
