<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_edit_permission_requests', function (Blueprint $table): void {
            $table->foreignId('admin_reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('admin_reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_edit_permission_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('admin_reviewed_by');
            $table->dropColumn('admin_reviewed_at');
        });
    }
};
