<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('collection_push_dedupe')) {
            Schema::create('collection_push_dedupe', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type', 64);
                $table->string('status_key', 64);
                $table->timestamps();

                $table->unique(
                    ['collection_id', 'user_id', 'type', 'status_key'],
                    'collection_push_dedupe_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_push_dedupe');
    }
};
