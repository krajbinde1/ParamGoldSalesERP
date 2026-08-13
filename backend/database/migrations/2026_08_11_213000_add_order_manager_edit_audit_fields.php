<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'last_edited_by')) {
                $table->foreignId('last_edited_by')
                    ->nullable()
                    ->after('rejection_remark')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('orders', 'last_edited_at')) {
                $table->timestamp('last_edited_at')->nullable()->after('last_edited_by');
            }

            if (! Schema::hasColumn('orders', 'last_edited_by_role')) {
                $table->string('last_edited_by_role', 50)->nullable()->after('last_edited_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'last_edited_by')) {
                $table->dropConstrainedForeignId('last_edited_by');
            }

            if (Schema::hasColumn('orders', 'last_edited_at')) {
                $table->dropColumn('last_edited_at');
            }

            if (Schema::hasColumn('orders', 'last_edited_by_role')) {
                $table->dropColumn('last_edited_by_role');
            }
        });
    }
};
