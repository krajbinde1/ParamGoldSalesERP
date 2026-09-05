<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent unique inventory codes (never reused after allocation).
 * Safe under concurrent bulk import via row lock on inventory_code_sequences.
 */
final class InventoryCodeGenerator
{
    public function next(string $prefix, int $pad = 6): string
    {
        $prefix = strtoupper(trim($prefix));

        return DB::transaction(function () use ($prefix, $pad): string {
            if (! Schema::hasTable('inventory_code_sequences')) {
                return $this->fallbackMaxPlusOne($prefix, $pad);
            }

            $row = DB::table('inventory_code_sequences')
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('inventory_code_sequences')->insert([
                    'prefix' => $prefix,
                    'last_number' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('inventory_code_sequences')
                    ->where('prefix', $prefix)
                    ->lockForUpdate()
                    ->first();
            }

            $next = ((int) ($row->last_number ?? 0)) + 1;

            DB::table('inventory_code_sequences')
                ->where('prefix', $prefix)
                ->update([
                    'last_number' => $next,
                    'updated_at' => now(),
                ]);

            return $prefix.str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
        });
    }

    public function nextRawMaterialCode(): string
    {
        return $this->next((string) config('inventory.raw_material_code_prefix', 'RM'));
    }

    public function nextPackagingMaterialCode(): string
    {
        return $this->next((string) config('inventory.packaging_material_code_prefix', 'PK'));
    }

    public function nextSemiFinishedCode(): string
    {
        return $this->next((string) config('inventory.semi_finished_code_prefix', 'SFM'));
    }

    public function nextFinishedProductCode(): string
    {
        return $this->next((string) config('inventory.finished_product_code_prefix', 'FP'));
    }

    public function nextBomNumber(): string
    {
        return $this->next((string) config('inventory.bom_number_prefix', 'BOM'));
    }

    public function nextPurchaseNumber(): string
    {
        return $this->next((string) config('inventory.purchase_prefix', 'PUR'));
    }

    private function fallbackMaxPlusOne(string $prefix, int $pad): string
    {
        $map = [
            (string) config('inventory.raw_material_code_prefix', 'RM') => ['raw_materials', 'material_code', false],
            (string) config('inventory.packaging_material_code_prefix', 'PK') => ['packaging_materials', 'packaging_code', false],
            (string) config('inventory.semi_finished_code_prefix', 'SFM') => ['semi_finished_materials', 'material_code', false],
            (string) config('inventory.finished_product_code_prefix', 'FP') => ['finished_products', 'finished_product_code', false],
            (string) config('inventory.bom_number_prefix', 'BOM') => ['boms', 'bom_number', false],
            (string) config('inventory.purchase_prefix', 'PUR') => ['purchases', 'purchase_number', false],
        ];

        [$table, $column, $withTrashed] = $map[$prefix] ?? [null, null, false];

        if ($table === null || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return $prefix.str_pad('1', $pad, '0', STR_PAD_LEFT);
        }

        $query = DB::table($table)->where($column, 'like', $prefix.'%');
        if ($withTrashed && Schema::hasColumn($table, 'deleted_at')) {
            // include soft-deleted rows
        }

        $lastCode = $query->orderByDesc($column)->value($column);
        $next = $lastCode === null ? 1 : ((int) substr((string) $lastCode, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
    }
}
