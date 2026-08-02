<?php

namespace App\Exports\Inventory;

use App\Enums\InventoryBulkImportType;
use App\Services\Inventory\BulkImport\InventoryBulkImportRowError;
use App\Services\Inventory\BulkImport\InventoryBulkImportTemplate;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

final class InventoryBulkImportErrorReportExport implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  list<InventoryBulkImportRowError>  $errors
     */
    public function __construct(
        private readonly InventoryBulkImportType $type,
        private readonly array $errors,
    ) {}

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        $columns = InventoryBulkImportTemplate::allColumns($this->type);

        return array_map(function (InventoryBulkImportRowError $error) use ($columns): array {
            $cells = [(string) $error->rowNumber];

            foreach ($columns as $column) {
                $cells[] = (string) ($error->rowData[$column] ?? '');
            }

            $cells[] = $error->reason;

            return $cells;
        }, $this->errors);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        $labels = InventoryBulkImportTemplate::columnLabels($this->type);
        $columns = InventoryBulkImportTemplate::allColumns($this->type);

        return array_merge(
            ['Row Number'],
            array_map(fn (string $column): string => $labels[$column], $columns),
            ['Error Reason'],
        );
    }

    public function title(): string
    {
        return 'Failed Rows';
    }
}
