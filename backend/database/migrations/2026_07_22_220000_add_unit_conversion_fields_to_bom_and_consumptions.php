<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bom_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('bom_items', 'inventory_unit')) {
                $table->string('inventory_unit', 30)->nullable()->after('unit');
            }
            if (! Schema::hasColumn('bom_items', 'inventory_equivalent_quantity')) {
                $table->decimal('inventory_equivalent_quantity', 18, 6)->nullable()->after('inventory_unit');
            }
            if (! Schema::hasColumn('bom_items', 'conversion_factor')) {
                $table->decimal('conversion_factor', 24, 12)->nullable()->after('inventory_equivalent_quantity');
            }
        });

        // Backfill: treat existing rows as already in inventory units (1:1).
        if (Schema::hasColumn('bom_items', 'inventory_unit')) {
            DB::table('bom_items')
                ->whereNull('inventory_unit')
                ->orderBy('id')
                ->chunkById(200, function ($rows): void {
                    foreach ($rows as $row) {
                        DB::table('bom_items')->where('id', $row->id)->update([
                            'inventory_unit' => $row->unit,
                            'inventory_equivalent_quantity' => $row->required_quantity,
                            'conversion_factor' => 1,
                            'calculated_quantity' => $row->required_quantity,
                        ]);
                    }
                });
        }

        Schema::table('production_batch_consumptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('production_batch_consumptions', 'formulation_quantity')) {
                $table->decimal('formulation_quantity', 18, 6)->nullable()->after('unit');
            }
            if (! Schema::hasColumn('production_batch_consumptions', 'formulation_unit')) {
                $table->string('formulation_unit', 30)->nullable()->after('formulation_quantity');
            }
            if (! Schema::hasColumn('production_batch_consumptions', 'inventory_unit')) {
                $table->string('inventory_unit', 30)->nullable()->after('formulation_unit');
            }
        });

        if (Schema::hasColumn('production_batch_consumptions', 'inventory_unit')) {
            DB::table('production_batch_consumptions')
                ->whereNull('inventory_unit')
                ->update([
                    'inventory_unit' => DB::raw('unit'),
                    'formulation_unit' => DB::raw('unit'),
                    'formulation_quantity' => DB::raw('consumed_quantity'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('production_batch_consumptions', function (Blueprint $table): void {
            foreach (['formulation_quantity', 'formulation_unit', 'inventory_unit'] as $column) {
                if (Schema::hasColumn('production_batch_consumptions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('bom_items', function (Blueprint $table): void {
            foreach (['inventory_unit', 'inventory_equivalent_quantity', 'conversion_factor'] as $column) {
                if (Schema::hasColumn('bom_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
