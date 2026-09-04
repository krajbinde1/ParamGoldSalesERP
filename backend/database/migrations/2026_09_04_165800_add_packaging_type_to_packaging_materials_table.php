<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packaging_materials', function (Blueprint $table) {
            $table->string('packaging_type', 30)->default('Other')->after('packaging_name');
            $table->index('packaging_type');
        });
    }

    public function down(): void
    {
        Schema::table('packaging_materials', function (Blueprint $table) {
            $table->dropIndex(['packaging_type']);
            $table->dropColumn('packaging_type');
        });
    }
};
