<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory bulk-import codes:
 * - finished_product_code (FP……) lives on finished_products (inventory master), not products.
 * - sales product_code (PRD……) stays on products unchanged.
 * - inventory_code_sequences provides locked, never-reuse numbering under concurrent imports.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_code_sequences')) {
            Schema::create('inventory_code_sequences', function (Blueprint $table): void {
                $table->string('prefix', 16)->primary();
                $table->unsignedBigInteger('last_number')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('finished_products')) {
            Schema::create('finished_products', function (Blueprint $table): void {
                $table->id();
                $table->string('finished_product_code', 32)->unique();
                $table->foreignId('product_id')->unique()->constrained('products')->restrictOnDelete();
                $table->string('unit', 30);
                $table->decimal('minimum_stock', 14, 3)->default(0);
                $table->boolean('status')->default(true);
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['status', 'finished_product_code']);
            });
        }

        $this->seedSequence('RM', 'raw_materials', 'material_code');
        $this->seedSequence('PK', 'packaging_materials', 'packaging_code');
        $this->seedSequence('SFM', 'semi_finished_materials', 'material_code');
        $this->seedSequence('FP', 'finished_products', 'finished_product_code');
        $this->seedSequence('BOM', 'boms', 'bom_number');

        $this->backfillFinishedProducts();
    }

    public function down(): void
    {
        Schema::dropIfExists('finished_products');
        Schema::dropIfExists('inventory_code_sequences');
    }

    private function seedSequence(string $prefix, string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        // Soft-deleted rows are included via DB::table (no Eloquent global scopes),
        // so codes are never reused after soft-delete.
        $lastCode = DB::table($table)
            ->where($column, 'like', $prefix.'%')
            ->orderByDesc($column)
            ->value($column);
        $lastNumber = $lastCode === null
            ? 0
            : (int) substr((string) $lastCode, strlen($prefix));

        DB::table('inventory_code_sequences')->updateOrInsert(
            ['prefix' => $prefix],
            [
                'last_number' => max(0, $lastNumber),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function backfillFinishedProducts(): void
    {
        if (! Schema::hasTable('finished_products') || ! Schema::hasTable('products')) {
            return;
        }

        $prefix = 'FP';
        $rows = DB::table('products')
            ->whereNull('deleted_at')
            ->where(function ($query): void {
                $query->where('manufacturing_enabled', true)
                    ->orWhere('current_finished_stock', '>', 0);
            })
            ->whereNotIn('id', DB::table('finished_products')->select('product_id'))
            ->orderBy('id')
            ->get(['id', 'production_unit', 'uom', 'minimum_finished_stock', 'status', 'remarks']);

        foreach ($rows as $row) {
            DB::transaction(function () use ($prefix, $row): void {
                $sequence = DB::table('inventory_code_sequences')
                    ->where('prefix', $prefix)
                    ->lockForUpdate()
                    ->first();

                $next = ((int) ($sequence->last_number ?? 0)) + 1;

                DB::table('inventory_code_sequences')->updateOrInsert(
                    ['prefix' => $prefix],
                    [
                        'last_number' => $next,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );

                $unit = filled($row->production_unit) ? (string) $row->production_unit : (string) ($row->uom ?: 'Nos');

                DB::table('finished_products')->insert([
                    'finished_product_code' => $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT),
                    'product_id' => $row->id,
                    'unit' => $unit,
                    'minimum_stock' => (float) ($row->minimum_finished_stock ?? 0),
                    'status' => (bool) ($row->status ?? true),
                    'remarks' => $row->remarks,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }
    }
};
