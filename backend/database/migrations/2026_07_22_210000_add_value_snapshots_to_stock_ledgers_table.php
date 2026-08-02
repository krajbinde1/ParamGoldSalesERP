<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ledgers', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_ledgers', 'opening_value')) {
                $table->decimal('opening_value', 18, 4)->nullable()->after('stock_after');
            }
            if (! Schema::hasColumn('stock_ledgers', 'closing_value')) {
                $table->decimal('closing_value', 18, 4)->nullable()->after('opening_value');
            }
            if (! Schema::hasColumn('stock_ledgers', 'average_rate_before')) {
                $table->decimal('average_rate_before', 18, 4)->nullable()->after('closing_value');
            }
            if (! Schema::hasColumn('stock_ledgers', 'average_rate_after')) {
                $table->decimal('average_rate_after', 18, 4)->nullable()->after('average_rate_before');
            }
            if (! Schema::hasColumn('stock_ledgers', 'inward_value')) {
                $table->decimal('inward_value', 18, 4)->nullable()->after('transaction_value');
            }
            if (! Schema::hasColumn('stock_ledgers', 'outward_value')) {
                $table->decimal('outward_value', 18, 4)->nullable()->after('inward_value');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_ledgers', function (Blueprint $table) {
            $columns = [
                'opening_value',
                'closing_value',
                'average_rate_before',
                'average_rate_after',
                'inward_value',
                'outward_value',
            ];

            $existing = array_values(array_filter(
                $columns,
                fn (string $column): bool => Schema::hasColumn('stock_ledgers', $column),
            ));

            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
