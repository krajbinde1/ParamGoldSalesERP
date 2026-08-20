<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mobile_app_settings')) {
            return;
        }

        Schema::create('mobile_app_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('latest_version', 32);
            $table->unsignedInteger('latest_build');
            $table->boolean('force_update')->default(true);
            $table->string('apk_url', 2048);
            $table->text('update_message')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_settings');
    }
};
