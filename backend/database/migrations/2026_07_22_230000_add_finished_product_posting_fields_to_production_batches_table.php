<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('production_batches', 'finished_product_posted_at')) {
                $table->timestamp('finished_product_posted_at')->nullable()->after('completed_at');
            }
            if (! Schema::hasColumn('production_batches', 'finished_stock_before')) {
                $table->decimal('finished_stock_before', 14, 3)->nullable()->after('finished_product_posted_at');
            }
            if (! Schema::hasColumn('production_batches', 'finished_stock_after')) {
                $table->decimal('finished_stock_after', 14, 3)->nullable()->after('finished_stock_before');
            }
            if (! Schema::hasColumn('production_batches', 'finished_stock_value_after')) {
                $table->decimal('finished_stock_value_after', 14, 2)->nullable()->after('finished_stock_after');
            }
            if (! Schema::hasColumn('production_batches', 'finished_product_ledger_id')) {
                $table->unsignedBigInteger('finished_product_ledger_id')->nullable()->after('finished_stock_value_after');
                $table->foreign('finished_product_ledger_id', 'pb_fg_ledger_fk')
                    ->references('id')
                    ->on('stock_ledgers')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            if (Schema::hasColumn('production_batches', 'finished_product_ledger_id')) {
                try {
                    $table->dropForeign('pb_fg_ledger_fk');
                } catch (Throwable) {
                    // ignore
                }
            }

            foreach ([
                'finished_product_ledger_id',
                'finished_stock_value_after',
                'finished_stock_after',
                'finished_stock_before',
                'finished_product_posted_at',
            ] as $col) {
                if (Schema::hasColumn('production_batches', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
