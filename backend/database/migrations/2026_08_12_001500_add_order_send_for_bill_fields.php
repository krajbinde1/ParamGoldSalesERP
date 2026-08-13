<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'sent_for_bill_by')) {
                $table->foreignId('sent_for_bill_by')
                    ->nullable()
                    ->after('approved_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('orders', 'sent_for_bill_at')) {
                $table->timestamp('sent_for_bill_at')->nullable()->after('sent_for_bill_by');
            }

            if (! Schema::hasColumn('orders', 'transport_remark')) {
                $table->text('transport_remark')->nullable()->after('vehicle_number');
            }

            if (! Schema::hasColumn('orders', 'bill_date')) {
                $table->date('bill_date')->nullable()->after('bill_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'sent_for_bill_by')) {
                $table->dropConstrainedForeignId('sent_for_bill_by');
            }
            if (Schema::hasColumn('orders', 'sent_for_bill_at')) {
                $table->dropColumn('sent_for_bill_at');
            }
            if (Schema::hasColumn('orders', 'transport_remark')) {
                $table->dropColumn('transport_remark');
            }
            if (Schema::hasColumn('orders', 'bill_date')) {
                $table->dropColumn('bill_date');
            }
        });
    }
};
