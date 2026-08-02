<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_route_points') && ! Schema::hasColumn('employee_route_points', 'heading')) {
            Schema::table('employee_route_points', function (Blueprint $table) {
                $table->decimal('heading', 8, 2)->nullable()->after('speed');
            });
        }

        if (Schema::hasTable('attendances') && ! Schema::hasColumn('attendances', 'total_route_distance_km')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->decimal('total_route_distance_km', 10, 2)->nullable()->after('total_working_minutes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_route_points') && Schema::hasColumn('employee_route_points', 'heading')) {
            Schema::table('employee_route_points', function (Blueprint $table) {
                $table->dropColumn('heading');
            });
        }

        if (Schema::hasTable('attendances') && Schema::hasColumn('attendances', 'total_route_distance_km')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropColumn('total_route_distance_km');
            });
        }
    }
};
