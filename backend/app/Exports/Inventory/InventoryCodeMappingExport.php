<?php

namespace App\Exports\Inventory;

use App\Enums\InventoryBulkImportType;
use App\Models\FinishedProduct;
use App\Models\PackagingMaterial;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Services\Inventory\BulkImport\InventoryBulkImportTemplate;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

final class InventoryCodeMappingExport implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  list<array<string, mixed>>|null  $rows  When null, export full module masters from DB.
     */
    public function __construct(
        private readonly InventoryBulkImportType $type,
        private readonly ?array $rows = null,
        private readonly bool $combined = false,
    ) {}

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        if ($this->combined) {
            return $this->combinedRows();
        }

        $source = $this->rows ?? $this->moduleRowsFromDatabase();
        $columns = InventoryBulkImportTemplate::codeMappingColumns($this->type);

        return array_map(function (array $row) use ($columns): array {
            return array_map(
                fn (string $column): string => (string) ($row[$column] ?? ''),
                $columns,
            );
        }, $source);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        if ($this->combined) {
            return ['Module', 'Code', 'Name', 'Unit', 'Active'];
        }

        $labels = InventoryBulkImportTemplate::codeMappingLabels($this->type);

        return array_map(
            fn (string $column): string => $labels[$column] ?? $column,
            InventoryBulkImportTemplate::codeMappingColumns($this->type),
        );
    }

    public function title(): string
    {
        if ($this->combined) {
            return 'Master Code Mapping';
        }

        return match ($this->type) {
            InventoryBulkImportType::RawMaterial => 'RM Code Mapping',
            InventoryBulkImportType::PackagingMaterial => 'PM Code Mapping',
            InventoryBulkImportType::SemiFinished => 'SF Code Mapping',
            InventoryBulkImportType::FinishedProduct => 'FP Code Mapping',
            InventoryBulkImportType::FinishedGoodsOpeningStock => 'FG Opening Stock Result',
            InventoryBulkImportType::Bom => 'BOM Mapping',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function moduleRowsFromDatabase(): array
    {
        return match ($this->type) {
            InventoryBulkImportType::RawMaterial => RawMaterial::query()
                ->orderBy('material_code')
                ->get()
                ->map(fn (RawMaterial $m): array => [
                    'material_code' => $m->material_code,
                    'material_name' => $m->material_name,
                    'unit' => $m->unit,
                    'active' => $m->status ? 'Yes' : 'No',
                ])
                ->all(),
            InventoryBulkImportType::PackagingMaterial => PackagingMaterial::query()
                ->orderBy('packaging_code')
                ->get()
                ->map(fn (PackagingMaterial $m): array => [
                    'packaging_code' => $m->packaging_code,
                    'packaging_name' => $m->packaging_name,
                    'packaging_type' => $m->packagingTypeLabel(),
                    'unit' => $m->unit,
                    'active' => $m->status ? 'Yes' : 'No',
                ])
                ->all(),
            InventoryBulkImportType::SemiFinished => SemiFinishedMaterial::query()
                ->orderBy('material_code')
                ->get()
                ->map(fn (SemiFinishedMaterial $m): array => [
                    'material_code' => $m->material_code,
                    'material_name' => $m->material_name,
                    'unit' => $m->unit,
                    'active' => $m->status ? 'Yes' : 'No',
                ])
                ->all(),
            InventoryBulkImportType::FinishedProduct => FinishedProduct::query()
                ->with('product')
                ->orderBy('finished_product_code')
                ->get()
                ->map(fn (FinishedProduct $fp): array => [
                    'finished_product_code' => $fp->finished_product_code,
                    'product_code' => $fp->product?->product_code,
                    'product_name' => $fp->product?->product_name,
                    'unit' => $fp->unit ?: ($fp->product?->production_unit ?: $fp->product?->uom),
                    'current_stock' => number_format($fp->currentStock(), 3, '.', ''),
                ])
                ->all(),
            InventoryBulkImportType::FinishedGoodsOpeningStock => [],
            InventoryBulkImportType::Bom => [],
        };
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function combinedRows(): array
    {
        $rows = [];

        foreach (RawMaterial::query()->orderBy('material_code')->get() as $material) {
            $rows[] = ['Raw Material', (string) $material->material_code, (string) $material->material_name, (string) $material->unit, $material->status ? 'Yes' : 'No'];
        }

        foreach (PackagingMaterial::query()->orderBy('packaging_code')->get() as $material) {
            $rows[] = ['Packaging Material', (string) $material->packaging_code, (string) $material->packaging_name, (string) $material->unit, $material->status ? 'Yes' : 'No'];
        }

        foreach (SemiFinishedMaterial::query()->orderBy('material_code')->get() as $material) {
            $rows[] = ['Semi Finished', (string) $material->material_code, (string) $material->material_name, (string) $material->unit, $material->status ? 'Yes' : 'No'];
        }

        foreach (FinishedProduct::query()->with('product')->orderBy('finished_product_code')->get() as $fp) {
            $rows[] = [
                'Finished Product',
                (string) $fp->finished_product_code,
                (string) ($fp->product?->product_name ?? ''),
                (string) ($fp->unit ?: ($fp->product?->production_unit ?: $fp->product?->uom)),
                $fp->status ? 'Yes' : 'No',
            ];
        }

        return $rows;
    }
}
