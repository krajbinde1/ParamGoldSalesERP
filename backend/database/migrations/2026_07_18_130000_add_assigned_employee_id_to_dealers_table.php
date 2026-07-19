<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealers', function (Blueprint $table): void {
            if (! Schema::hasColumn('dealers', 'assigned_employee_id')) {
                $table->foreignId('assigned_employee_id')
                    ->nullable()
                    ->after('dealer_type')
                    ->constrained('employees')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('dealers', function (Blueprint $table): void {
            if (Schema::hasColumn('dealers', 'assigned_employee_id')) {
                $table->dropConstrainedForeignId('assigned_employee_id');
            }
        });
    }
};
