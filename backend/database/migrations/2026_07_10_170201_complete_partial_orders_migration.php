<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'sales_employee_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('sales_employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->enum('payment_type', ['Cash', 'Credit'])->default('Credit');
                $table->text('remarks')->nullable();
                $table->enum('status', ['Draft', 'Confirmed', 'Dispatched', 'Delivered', 'Cancelled'])->default('Draft');
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('discount_amount', 14, 2)->default(0);
                $table->decimal('gst_amount', 14, 2)->default(0);
                $table->decimal('grand_total', 14, 2)->default(0);
                $table->softDeletes();
                $table->index(['status', 'order_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'order_date']);
            $table->dropForeign(['sales_employee_id']);
            $table->dropSoftDeletes();
            $table->dropColumn(['sales_employee_id', 'payment_type', 'remarks', 'status', 'subtotal', 'discount_amount', 'gst_amount', 'grand_total']);
        });
    }
};
