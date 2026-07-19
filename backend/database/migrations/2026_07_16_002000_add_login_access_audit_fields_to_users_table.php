<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('password_reset_by')
                ->nullable()
                ->after('must_change_password')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('password_reset_at')->nullable()->after('password_reset_by');
            $table->foreignId('login_id_changed_by')
                ->nullable()
                ->after('password_reset_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('login_id_changed_at')->nullable()->after('login_id_changed_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('password_reset_by');
            $table->dropConstrainedForeignId('login_id_changed_by');
            $table->dropColumn(['password_reset_at', 'login_id_changed_at']);
        });
    }
};
