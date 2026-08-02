<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finished Product Master (Approach A) stores inventory metadata on products.
 * Stock source of truth remains current_finished_stock / weighted_average_cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'opening_finished_stock')) {
                $table->decimal('opening_finished_stock', 14, 3)
                    ->default(0)
                    ->after('current_finished_stock');
            }

            if (! Schema::hasColumn('products', 'expiry_tracking_enabled')) {
                $table->boolean('expiry_tracking_enabled')
                    ->default(false)
                    ->after('batch_tracking_enabled');
            }

            if (! Schema::hasColumn('products', 'remarks')) {
                $table->text('remarks')
                    ->nullable()
                    ->after('expiry_tracking_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('products', 'opening_finished_stock') ? 'opening_finished_stock' : null,
                Schema::hasColumn('products', 'expiry_tracking_enabled') ? 'expiry_tracking_enabled' : null,
                Schema::hasColumn('products', 'remarks') ? 'remarks' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
