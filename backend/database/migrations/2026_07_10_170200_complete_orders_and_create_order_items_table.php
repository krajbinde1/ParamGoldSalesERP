<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'order_no')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('order_no')->nullable()->after('id');
            });
        }

        if (! Schema::hasColumn('orders', 'order_date')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->date('order_date')->default(now()->toDateString())->after('order_no');
                $table->foreignId('dealer_id')->nullable();
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

        if (Schema::hasColumn('orders', 'dealer_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('dealer_id')->nullable()->change();
            });
        }

        DB::table('orders')
            ->where(fn ($query) => $query->whereNull('order_no')->orWhere('order_no', ''))
            ->orderBy('id')
            ->eachById(fn (object $order) => DB::table('orders')->where('id', $order->id)->update([
                'order_no' => 'ORD'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
            ]));

        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_no')->nullable(false)->change();
        });

        if (! Schema::hasIndex('orders', 'orders_order_no_unique')) {
            Schema::table('orders', fn (Blueprint $table) => $table->unique('order_no'));
        }

        if (Schema::hasTable('order_items')) {
            return;
        }

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 30);
            $table->decimal('rate', 12, 2);
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('gst_percentage', 5, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropIndex(['status', 'order_date']);
            $table->dropForeign(['dealer_id', 'sales_employee_id']);
            $table->dropUnique(['order_no']);
            $table->dropColumn(['order_no', 'order_date', 'dealer_id', 'sales_employee_id', 'payment_type', 'remarks', 'status', 'subtotal', 'discount_amount', 'gst_amount', 'grand_total']);
        });
    }
};
