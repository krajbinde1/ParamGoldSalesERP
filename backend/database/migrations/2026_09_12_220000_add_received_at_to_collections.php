<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('collections') || Schema::hasColumn('collections', 'received_at')) {
            return;
        }

        Schema::table('collections', function (Blueprint $table): void {
            $table->timestamp('received_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('collections') || ! Schema::hasColumn('collections', 'received_at')) {
            return;
        }

        Schema::table('collections', function (Blueprint $table): void {
            $table->dropColumn('received_at');
        });
    }
};
