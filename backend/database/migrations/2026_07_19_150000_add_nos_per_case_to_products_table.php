<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'nos_per_case')) {
                $table->unsignedInteger('nos_per_case')
                    ->default(1)
                    ->after('uom');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'nos_per_case')) {
                $table->dropColumn('nos_per_case');
            }
        });
    }
};
