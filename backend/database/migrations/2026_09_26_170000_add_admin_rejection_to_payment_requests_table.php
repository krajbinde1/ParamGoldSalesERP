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
            if (! Schema::hasColumn('payment_requests', 'rejected_by')) {
                $table->foreignId('rejected_by')
                    ->nullable()
                    ->after('payment_proof_path')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('payment_requests', 'rejected_by_name')) {
                $table->string('rejected_by_name')->nullable()->after('rejected_by');
            }
            if (! Schema::hasColumn('payment_requests', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('rejected_by_name');
            }
            if (! Schema::hasColumn('payment_requests', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('rejected_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_requests')) {
            return;
        }

        Schema::table('payment_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('payment_requests', 'rejected_by')) {
                $table->dropConstrainedForeignId('rejected_by');
            }
            if (Schema::hasColumn('payment_requests', 'rejected_by_name')) {
                $table->dropColumn('rejected_by_name');
            }
            if (Schema::hasColumn('payment_requests', 'rejected_at')) {
                $table->dropColumn('rejected_at');
            }
            if (Schema::hasColumn('payment_requests', 'rejection_reason')) {
                $table->dropColumn('rejection_reason');
            }
        });
    }
};
