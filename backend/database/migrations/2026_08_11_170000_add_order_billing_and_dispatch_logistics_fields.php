<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'billed_by')) {
                $table->foreignId('billed_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('orders', 'billed_at')) {
                $table->timestamp('billed_at')->nullable()->after('billed_by');
            }
            if (! Schema::hasColumn('orders', 'bill_path')) {
                $table->string('bill_path')->nullable()->after('billed_at');
            }
            if (! Schema::hasColumn('orders', 'bill_number')) {
                $table->string('bill_number', 100)->nullable()->after('bill_path');
            }
            if (! Schema::hasColumn('orders', 'billing_remark')) {
                $table->text('billing_remark')->nullable()->after('bill_number');
            }
            if (! Schema::hasColumn('orders', 'dispatch_date')) {
                $table->date('dispatch_date')->nullable()->after('dispatched_at');
            }
            if (! Schema::hasColumn('orders', 'transporter_name')) {
                $table->string('transporter_name')->nullable()->after('transport_amount');
            }
            if (! Schema::hasColumn('orders', 'vehicle_number')) {
                $table->string('vehicle_number', 50)->nullable()->after('transporter_name');
            }
            if (! Schema::hasColumn('orders', 'lr_number')) {
                $table->string('lr_number', 100)->nullable()->after('vehicle_number');
            }
            if (! Schema::hasColumn('orders', 'lr_document_path')) {
                $table->string('lr_document_path')->nullable()->after('lr_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = [
                'billed_by',
                'billed_at',
                'bill_path',
                'bill_number',
                'billing_remark',
                'dispatch_date',
                'transporter_name',
                'vehicle_number',
                'lr_number',
                'lr_document_path',
            ];

            foreach ($columns as $column) {
                if (! Schema::hasColumn('orders', $column)) {
                    continue;
                }

                if ($column === 'billed_by') {
                    $table->dropConstrainedForeignId('billed_by');
                } else {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
