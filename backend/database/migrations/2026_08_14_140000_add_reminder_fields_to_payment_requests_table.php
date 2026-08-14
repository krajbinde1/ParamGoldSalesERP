<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_requests')) {
            return;
        }

        Schema::table('payment_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_requests', 'reminder_count')) {
                $table->unsignedInteger('reminder_count')->default(0)->after('payment_proof_path');
            }
            if (! Schema::hasColumn('payment_requests', 'last_reminded_at')) {
                $table->timestamp('last_reminded_at')->nullable()->after('reminder_count');
            }
            if (! Schema::hasColumn('payment_requests', 'last_reminded_by')) {
                $table->foreignId('last_reminded_by')
                    ->nullable()
                    ->after('last_reminded_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_requests')) {
            return;
        }

        Schema::table('payment_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('payment_requests', 'last_reminded_by')) {
                $table->dropConstrainedForeignId('last_reminded_by');
            }
            if (Schema::hasColumn('payment_requests', 'last_reminded_at')) {
                $table->dropColumn('last_reminded_at');
            }
            if (Schema::hasColumn('payment_requests', 'reminder_count')) {
                $table->dropColumn('reminder_count');
            }
        });
    }
};
