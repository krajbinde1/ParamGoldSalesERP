<?php

namespace App\Exports;

use App\Services\Products\ProductBulkImportTemplate;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

final class ProductImportTemplateExport implements FromArray, WithTitle
{
    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        $columns = ProductBulkImportTemplate::allColumns();

        return [
            array_merge(
                ['Requirement'],
                array_map(
                    fn (string $column): string => in_array($column, ProductBulkImportTemplate::MANDATORY_COLUMNS, true)
                        ? 'MANDATORY'
                        : 'OPTIONAL',
                    $columns,
                ),
            ),
            array_merge(['Column'], ProductBulkImportTemplate::columnLabels()),
            array_merge(['Example'], [
                'Sample Fertilizer',
                '',
                '450.00',
                '20',
                '12',
                'Active',
            ]),
        ];
    }

    public function title(): string
    {
        return 'Products';
    }
}
