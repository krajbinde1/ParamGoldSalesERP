<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealers', function (Blueprint $table): void {
            $table->string('owner_name')->nullable()->change();
            $table->text('address')->nullable()->change();
            $table->string('pincode', 10)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('dealers', function (Blueprint $table): void {
            $table->string('owner_name')->nullable(false)->change();
            $table->text('address')->nullable(false)->change();
            $table->string('pincode', 10)->nullable(false)->change();
        });
    }
};
