<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('transport_cost', 14, 2)->default(0)->after('grand_total');
            $table->string('transporter_name')->nullable()->after('transport_cost');
            $table->string('transport_invoice_lr_no', 80)->nullable()->after('transporter_name');
            $table->text('transport_remark')->nullable()->after('transport_invoice_lr_no');
            $table->decimal('total_landed_cost', 14, 2)->default(0)->after('transport_remark');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('allocated_transport_cost', 14, 2)->default(0)->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn('allocated_transport_cost');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn([
                'transport_cost',
                'transporter_name',
                'transport_invoice_lr_no',
                'transport_remark',
                'total_landed_cost',
            ]);
        });
    }
};
