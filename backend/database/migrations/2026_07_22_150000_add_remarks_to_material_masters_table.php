<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            if (! Schema::hasColumn('raw_materials', 'remarks')) {
                $table->text('remarks')->nullable()->after('status');
            }
        });

        Schema::table('packaging_materials', function (Blueprint $table) {
            if (! Schema::hasColumn('packaging_materials', 'remarks')) {
                $table->text('remarks')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            if (Schema::hasColumn('raw_materials', 'remarks')) {
                $table->dropColumn('remarks');
            }
        });

        Schema::table('packaging_materials', function (Blueprint $table) {
            if (Schema::hasColumn('packaging_materials', 'remarks')) {
                $table->dropColumn('remarks');
            }
        });
    }
};
