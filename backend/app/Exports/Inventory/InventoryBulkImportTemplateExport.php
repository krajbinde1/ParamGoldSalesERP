<?php

namespace App\Exports\Inventory;

use App\Enums\InventoryBulkImportType;
use App\Services\Inventory\BulkImport\InventoryBulkImportTemplate;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

final class InventoryBulkImportTemplateExport implements FromArray, WithTitle
{
    public function __construct(
        private readonly InventoryBulkImportType $type,
    ) {}

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        $columns = InventoryBulkImportTemplate::allColumns($this->type);
        $labels = InventoryBulkImportTemplate::columnLabels($this->type);
        $mandatory = InventoryBulkImportTemplate::mandatoryColumns($this->type);

        return [
            array_merge(
                ['Requirement'],
                array_map(
                    fn (string $column): string => in_array($column, $mandatory, true) ? 'MANDATORY' : 'OPTIONAL',
                    $columns,
                ),
            ),
            array_merge(
                ['Column'],
                array_map(fn (string $column): string => $labels[$column], $columns),
            ),
            array_merge(['Example'], InventoryBulkImportTemplate::sampleRow($this->type)),
        ];
    }

    public function title(): string
    {
        return match ($this->type) {
            InventoryBulkImportType::RawMaterial => 'Raw Materials',
            InventoryBulkImportType::PackagingMaterial => 'Packaging',
            InventoryBulkImportType::SemiFinished => 'Semi Finished',
            InventoryBulkImportType::FinishedProduct => 'Finished Products',
            InventoryBulkImportType::FinishedGoodsOpeningStock => 'FG Opening Stock',
            InventoryBulkImportType::Bom => 'BOM',
        };
    }
}
