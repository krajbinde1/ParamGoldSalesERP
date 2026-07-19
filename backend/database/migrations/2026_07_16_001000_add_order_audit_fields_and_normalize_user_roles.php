<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('orders', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('orders', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('orders', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            }
            if (! Schema::hasColumn('orders', 'rejection_remark')) {
                $table->text('rejection_remark')->nullable()->after('rejected_at');
            }
            if (! Schema::hasColumn('orders', 'dispatched_by')) {
                $table->foreignId('dispatched_by')->nullable()->after('rejection_remark')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('orders', 'dispatched_at')) {
                $table->timestamp('dispatched_at')->nullable()->after('dispatched_by');
            }
            if (! Schema::hasColumn('orders', 'dispatch_remark')) {
                $table->text('dispatch_remark')->nullable()->after('dispatched_at');
            }
        });

        DB::table('users')
            ->whereNotIn('role', ['employee', 'manager', 'production_supervisor', 'director'])
            ->update(['role' => 'employee']);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = [
                'approved_by', 'approved_at', 'rejected_by', 'rejected_at',
                'rejection_remark', 'dispatched_by', 'dispatched_at', 'dispatch_remark',
            ];

            foreach ($columns as $column) {
                if (! Schema::hasColumn('orders', $column)) {
                    continue;
                }

                if (in_array($column, ['approved_by', 'rejected_by', 'dispatched_by'], true)) {
                    $table->dropConstrainedForeignId($column);
                } else {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
