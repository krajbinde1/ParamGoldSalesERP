<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            if (! Schema::hasColumn('boms', 'output_type')) {
                $table->string('output_type', 30)->default('finished_product')->after('bom_number');
            }
            if (! Schema::hasColumn('boms', 'semi_finished_id')) {
                $table->unsignedBigInteger('semi_finished_id')->nullable()->after('product_id');
            }
        });

        DB::table('boms')->whereNull('output_type')->orWhere('output_type', '')->update([
            'output_type' => 'finished_product',
        ]);

        $this->nullableUnsignedBigInteger('boms', 'product_id');

        if (Schema::hasColumn('boms', 'semi_finished_id')) {
            $this->ensureForeignKey('boms', 'semi_finished_id', 'semi_finished_materials', 'boms_semi_finished_id_foreign');
        }

        try {
            Schema::table('boms', function (Blueprint $table) {
                $table->index(['output_type', 'status'], 'boms_output_type_status_idx');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('boms', function (Blueprint $table) {
                $table->index(['semi_finished_id', 'status'], 'boms_semi_finished_status_idx');
            });
        } catch (\Throwable) {
        }

        Schema::table('bom_items', function (Blueprint $table) {
            if (! Schema::hasColumn('bom_items', 'semi_finished_id')) {
                $table->unsignedBigInteger('semi_finished_id')->nullable()->after('packaging_material_id');
            }
        });
        $this->ensureForeignKey('bom_items', 'semi_finished_id', 'semi_finished_materials', 'bom_items_semi_finished_id_foreign');

        Schema::table('stock_ledgers', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_ledgers', 'semi_finished_id')) {
                $table->unsignedBigInteger('semi_finished_id')->nullable()->after('product_id');
            }
        });
        $this->ensureForeignKey('stock_ledgers', 'semi_finished_id', 'semi_finished_materials', 'stock_ledgers_semi_finished_id_foreign');
        try {
            Schema::table('stock_ledgers', function (Blueprint $table) {
                $table->index(['item_type', 'semi_finished_id'], 'stock_ledgers_item_sf_idx');
            });
        } catch (\Throwable) {
        }

        Schema::table('production_batch_consumptions', function (Blueprint $table) {
            if (! Schema::hasColumn('production_batch_consumptions', 'semi_finished_id')) {
                $table->unsignedBigInteger('semi_finished_id')->nullable()->after('packaging_material_id');
            }
        });
        $this->ensureForeignKey(
            'production_batch_consumptions',
            'semi_finished_id',
            'semi_finished_materials',
            'pbc_semi_finished_id_foreign'
        );

        if (Schema::hasTable('stock_adjustments') && ! Schema::hasColumn('stock_adjustments', 'semi_finished_id')) {
            Schema::table('stock_adjustments', function (Blueprint $table) {
                $table->unsignedBigInteger('semi_finished_id')->nullable()->after('product_id');
            });
            $this->ensureForeignKey(
                'stock_adjustments',
                'semi_finished_id',
                'semi_finished_materials',
                'stock_adjustments_semi_finished_id_foreign'
            );
        }

        Schema::table('production_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('production_batches', 'output_type')) {
                $table->string('output_type', 30)->default('finished_product')->after('batch_number');
            }
            if (! Schema::hasColumn('production_batches', 'semi_finished_id')) {
                $table->unsignedBigInteger('semi_finished_id')->nullable()->after('product_id');
            }
            if (! Schema::hasColumn('production_batches', 'semi_finished_posted_at')) {
                $table->timestamp('semi_finished_posted_at')->nullable();
                $table->decimal('semi_finished_stock_before', 14, 3)->nullable();
                $table->decimal('semi_finished_stock_after', 14, 3)->nullable();
                $table->decimal('semi_finished_stock_value_after', 14, 2)->nullable();
                $table->unsignedBigInteger('semi_finished_ledger_id')->nullable();
            }
        });

        DB::table('production_batches')->whereNull('output_type')->orWhere('output_type', '')->update([
            'output_type' => 'finished_product',
        ]);

        $this->nullableUnsignedBigInteger('production_batches', 'product_id');
        $this->ensureForeignKey(
            'production_batches',
            'semi_finished_id',
            'semi_finished_materials',
            'production_batches_semi_finished_id_foreign'
        );
        $this->ensureForeignKey(
            'production_batches',
            'semi_finished_ledger_id',
            'stock_ledgers',
            'production_batches_semi_finished_ledger_id_foreign'
        );

        try {
            Schema::table('production_batches', function (Blueprint $table) {
                $table->index(['output_type', 'status'], 'pb_output_type_status_idx');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('production_batches', function (Blueprint $table) {
                $table->index(['semi_finished_id', 'status'], 'pb_semi_finished_status_idx');
            });
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        // Non-destructive down retained as no-op for safety in production deploys.
    }

    private function nullableUnsignedBigInteger(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        // Drop FK first if present.
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropForeign([$column]);
            });
        } catch (\Throwable) {
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` BIGINT UNSIGNED NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP NOT NULL");
        } else {
            // SQLite (and others): use Laravel schema change.
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->unsignedBigInteger($column)->nullable()->change();
            });
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->foreign($column)->references('id')->on('products')->nullOnDelete();
            });
        } catch (\Throwable) {
        }
    }

    private function ensureForeignKey(string $table, string $column, string $referenced, string $name): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $referenced, $name): void {
                $blueprint->foreign($column, $name)
                    ->references('id')
                    ->on($referenced)
                    ->nullOnDelete();
            });
        } catch (\Throwable) {
            // Already exists.
        }
    }
};
