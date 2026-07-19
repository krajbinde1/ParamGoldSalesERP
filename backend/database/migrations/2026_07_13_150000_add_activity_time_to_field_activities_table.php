<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_activities', function (Blueprint $table) {
            $table->time('activity_time')->nullable()->after('activity_date');
        });
    }

    public function down(): void
    {
        Schema::table('field_activities', function (Blueprint $table) {
            $table->dropColumn('activity_time');
        });
    }
};
