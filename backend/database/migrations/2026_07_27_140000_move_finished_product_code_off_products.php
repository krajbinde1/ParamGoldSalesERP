<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensures finished_product_code never lives on products.
 * Migrates any legacy products.finished_product_code values into finished_products,
 * then drops the products column if present.
 */
return new class extends Migration
{
    public function up(): void
    {
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

        if (Schema::hasColumn('products', 'finished_product_code')) {
            $legacyRows = DB::table('products')
                ->whereNotNull('finished_product_code')
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get([
                    'id',
                    'finished_product_code',
                    'production_unit',
                    'uom',
                    'minimum_finished_stock',
                    'status',
                    'remarks',
                ]);

            foreach ($legacyRows as $row) {
                $exists = DB::table('finished_products')
                    ->where('product_id', $row->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $code = (string) $row->finished_product_code;
                $codeTaken = DB::table('finished_products')
                    ->where('finished_product_code', $code)
                    ->exists();

                if ($codeTaken) {
                    $code = $this->allocateFpCode();
                }

                $unit = filled($row->production_unit)
                    ? (string) $row->production_unit
                    : (string) ($row->uom ?: 'Nos');

                DB::table('finished_products')->insert([
                    'finished_product_code' => $code,
                    'product_id' => $row->id,
                    'unit' => $unit,
                    'minimum_stock' => (float) ($row->minimum_finished_stock ?? 0),
                    'status' => (bool) ($row->status ?? true),
                    'remarks' => $row->remarks,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Schema::table('products', function (Blueprint $table): void {
                $table->dropUnique(['finished_product_code']);
                $table->dropColumn('finished_product_code');
            });
        }

        // Backfill FG masters that never received a finished_product_code on products.
        $missingFg = DB::table('products')
            ->whereNull('deleted_at')
            ->where(function ($query): void {
                $query->where('manufacturing_enabled', true)
                    ->orWhere('current_finished_stock', '>', 0);
            })
            ->whereNotIn('id', DB::table('finished_products')->select('product_id'))
            ->orderBy('id')
            ->get(['id', 'production_unit', 'uom', 'minimum_finished_stock', 'status', 'remarks']);

        foreach ($missingFg as $row) {
            $unit = filled($row->production_unit)
                ? (string) $row->production_unit
                : (string) ($row->uom ?: 'Nos');

            DB::table('finished_products')->insert([
                'finished_product_code' => $this->allocateFpCode(),
                'product_id' => $row->id,
                'unit' => $unit,
                'minimum_stock' => (float) ($row->minimum_finished_stock ?? 0),
                'status' => (bool) ($row->status ?? true),
                'remarks' => $row->remarks,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Re-seed FP sequence from finished_products (authoritative).
        if (Schema::hasTable('inventory_code_sequences') && Schema::hasTable('finished_products')) {
            $lastCode = DB::table('finished_products')
                ->where('finished_product_code', 'like', 'FP%')
                ->orderByDesc('finished_product_code')
                ->value('finished_product_code');
            $lastNumber = $lastCode === null
                ? 0
                : (int) substr((string) $lastCode, 2);

            DB::table('inventory_code_sequences')->updateOrInsert(
                ['prefix' => 'FP'],
                [
                    'last_number' => max(0, $lastNumber),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'finished_product_code')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('finished_product_code', 32)
                    ->nullable()
                    ->unique()
                    ->after('product_code');
            });
        }

        if (! Schema::hasTable('finished_products')) {
            return;
        }

        foreach (DB::table('finished_products')->orderBy('id')->get() as $row) {
            DB::table('products')
                ->where('id', $row->product_id)
                ->update(['finished_product_code' => $row->finished_product_code]);
        }
    }

    private function allocateFpCode(): string
    {
        return DB::transaction(function (): string {
            if (! Schema::hasTable('inventory_code_sequences')) {
                $lastCode = DB::table('finished_products')
                    ->where('finished_product_code', 'like', 'FP%')
                    ->orderByDesc('finished_product_code')
                    ->value('finished_product_code');
                $next = $lastCode === null ? 1 : ((int) substr((string) $lastCode, 2)) + 1;

                return 'FP'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            }

            $sequence = DB::table('inventory_code_sequences')
                ->where('prefix', 'FP')
                ->lockForUpdate()
                ->first();

            $next = ((int) ($sequence->last_number ?? 0)) + 1;

            DB::table('inventory_code_sequences')->updateOrInsert(
                ['prefix' => 'FP'],
                [
                    'last_number' => $next,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            return 'FP'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        });
    }
};
