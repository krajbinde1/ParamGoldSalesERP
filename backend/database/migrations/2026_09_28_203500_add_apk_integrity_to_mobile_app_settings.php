<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mobile_app_settings')) {
            return;
        }

        Schema::table('mobile_app_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('mobile_app_settings', 'apk_file_size')) {
                $table->unsignedBigInteger('apk_file_size')->nullable()->after('apk_url');
            }

            if (! Schema::hasColumn('mobile_app_settings', 'apk_sha256')) {
                $table->char('apk_sha256', 64)->nullable()->after('apk_file_size');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mobile_app_settings')) {
            return;
        }

        Schema::table('mobile_app_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('mobile_app_settings', 'apk_sha256')) {
                $table->dropColumn('apk_sha256');
            }

            if (Schema::hasColumn('mobile_app_settings', 'apk_file_size')) {
                $table->dropColumn('apk_file_size');
            }
        });
    }
};
