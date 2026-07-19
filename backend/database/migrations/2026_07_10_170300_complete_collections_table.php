<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->string('receipt_no')->nullable()->unique()->after('id');
            $table->date('collection_date')->default(now()->toDateString());
            $table->foreignId('dealer_id')->nullable();
            $table->foreignId('sales_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('payment_mode', ['Cash', 'Cheque', 'UPI', 'NEFT', 'RTGS'])->default('Cash');
            $table->string('bank_name')->nullable();
            $table->string('transaction_number')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->foreignId('reference_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->enum('status', ['Pending', 'Verified', 'Cancelled'])->default('Pending');
            $table->softDeletes();
            $table->index(['status', 'collection_date']);
            $table->unique(['payment_mode', 'transaction_number']);
        });

        DB::table('collections')->whereNull('receipt_no')->orderBy('id')->eachById(fn (object $collection) => DB::table('collections')->where('id', $collection->id)->update([
            'receipt_no' => 'RCP'.str_pad((string) $collection->id, 6, '0', STR_PAD_LEFT),
        ]));

        Schema::table('collections', fn (Blueprint $table) => $table->string('receipt_no')->nullable(false)->change());
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropIndex(['status', 'collection_date']);
            $table->dropUnique(['receipt_no']);
            $table->dropUnique(['payment_mode', 'transaction_number']);
            $table->dropForeign(['sales_employee_id', 'reference_order_id']);
            $table->dropColumn(['receipt_no', 'collection_date', 'dealer_id', 'sales_employee_id', 'payment_mode', 'bank_name', 'transaction_number', 'amount', 'reference_order_id', 'remarks', 'status']);
        });
    }
};
