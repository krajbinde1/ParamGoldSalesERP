<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes that speed Filament inventory list/filter/ledger lookups.
 * Only adds indexes that are not already covered by create-table FKs/composites.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_ledgers') && ! $this->hasIndex('stock_ledgers', 'stock_ledgers_created_at_index')) {
            Schema::table('stock_ledgers', function (Blueprint $table): void {
                $table->index('created_at', 'stock_ledgers_created_at_index');
            });
        }

        if (Schema::hasTable('raw_materials') && ! $this->hasIndex('raw_materials', 'raw_materials_status_stock_index')) {
            Schema::table('raw_materials', function (Blueprint $table): void {
                $table->index(['status', 'current_stock'], 'raw_materials_status_stock_index');
            });
        }

        if (Schema::hasTable('packaging_materials') && ! $this->hasIndex('packaging_materials', 'packaging_materials_status_stock_index')) {
            Schema::table('packaging_materials', function (Blueprint $table): void {
                $table->index(['status', 'current_stock'], 'packaging_materials_status_stock_index');
            });
        }

        if (Schema::hasTable('packaging_material_inwards') && ! $this->hasIndex('packaging_material_inwards', 'pmi_status_date_idx')) {
            Schema::table('packaging_material_inwards', function (Blueprint $table): void {
                $table->index(['status', 'inward_date'], 'pmi_status_date_idx');
            });
        }

        if (Schema::hasTable('production_batches') && ! $this->hasIndex('production_batches', 'pb_created_at_index')) {
            Schema::table('production_batches', function (Blueprint $table): void {
                $table->index('created_at', 'pb_created_at_index');
            });
        }
    }

    public function down(): void
    {
        $drops = [
            'stock_ledgers' => 'stock_ledgers_created_at_index',
            'raw_materials' => 'raw_materials_status_stock_index',
            'packaging_materials' => 'packaging_materials_status_stock_index',
            'packaging_material_inwards' => 'pmi_status_date_idx',
            'production_batches' => 'pb_created_at_index',
        ];

        foreach ($drops as $tableName => $index) {
            if (! Schema::hasTable($tableName) || ! $this->hasIndex($tableName, $index)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($index): void {
                $table->dropIndex($index);
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $sm = Schema::getConnection()->getSchemaBuilder();

        if (! method_exists($sm, 'getIndexes')) {
            return false;
        }

        foreach ($sm->getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
