<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->string('move_to', 32)->nullable()->after('type');
            $table->foreignId('destination_dealer_id')
                ->nullable()
                ->after('dealer_id')
                ->constrained('dealers')
                ->nullOnDelete();
            $table->foreignId('linked_order_id')
                ->nullable()
                ->after('destination_dealer_id')
                ->constrained('orders')
                ->nullOnDelete();
            $table->foreignId('production_approved_by')
                ->nullable()
                ->after('approval_remark')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('production_approved_at')->nullable()->after('production_approved_by');
            $table->text('production_approval_remark')->nullable()->after('production_approved_at');
            $table->timestamp('stock_posted_at')->nullable()->after('production_approval_remark');

            $table->index(['move_to', 'status']);
        });

        Schema::table('credit_note_items', function (Blueprint $table) {
            $table->unsignedInteger('case_quantity')->nullable()->after('product_id');
            $table->unsignedInteger('nos_per_case')->nullable()->after('case_quantity');
            $table->unsignedInteger('total_quantity_nos')->nullable()->after('nos_per_case');
            $table->decimal('rate_per_no', 12, 2)->nullable()->after('rate');
            $table->string('rate_type', 32)->nullable()->after('rate_per_no');
            $table->decimal('discount_percentage', 8, 2)->nullable()->after('rate_type');
            $table->decimal('discount_amount', 12, 2)->nullable()->after('discount_percentage');
            $table->decimal('gst_percentage', 8, 2)->nullable()->after('discount_amount');
            $table->decimal('base_amount', 12, 2)->nullable()->after('gst_percentage');
            $table->decimal('taxable_amount', 12, 2)->nullable()->after('base_amount');
            $table->decimal('gst_amount', 12, 2)->nullable()->after('taxable_amount');
            $table->decimal('final_amount', 12, 2)->nullable()->after('gst_amount');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('source_credit_note_id')
                ->nullable()
                ->after('sales_employee_id')
                ->constrained('credit_notes')
                ->nullOnDelete();
            $table->unique('source_credit_note_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['source_credit_note_id']);
            $table->dropConstrainedForeignId('source_credit_note_id');
        });

        Schema::table('credit_note_items', function (Blueprint $table) {
            $table->dropColumn([
                'case_quantity',
                'nos_per_case',
                'total_quantity_nos',
                'rate_per_no',
                'rate_type',
                'discount_percentage',
                'discount_amount',
                'gst_percentage',
                'base_amount',
                'taxable_amount',
                'gst_amount',
                'final_amount',
            ]);
        });

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropIndex(['move_to', 'status']);
            $table->dropConstrainedForeignId('destination_dealer_id');
            $table->dropConstrainedForeignId('linked_order_id');
            $table->dropConstrainedForeignId('production_approved_by');
            $table->dropColumn([
                'move_to',
                'production_approved_at',
                'production_approval_remark',
                'stock_posted_at',
            ]);
        });
    }
};
