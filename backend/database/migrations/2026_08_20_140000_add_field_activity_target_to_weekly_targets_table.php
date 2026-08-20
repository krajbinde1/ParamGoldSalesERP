<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_targets', function (Blueprint $table) {
            $table->unsignedInteger('field_activity_target')->default(0)->after('collection_target');
            $table->text('remark')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_targets', function (Blueprint $table) {
            $table->dropColumn(['field_activity_target', 'remark']);
        });
    }
};
