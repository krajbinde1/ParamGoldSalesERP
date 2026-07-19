<?php

namespace App\Exports;

use App\Services\Employees\EmployeeBulkImportTemplate;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

final class EmployeeImportTemplateExport implements FromArray, WithTitle
{
    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        $columns = EmployeeBulkImportTemplate::allColumns();

        return [
            array_merge(
                ['Requirement'],
                array_map(
                    fn (string $column): string => in_array($column, EmployeeBulkImportTemplate::MANDATORY_COLUMNS, true)
                        ? 'MANDATORY'
                        : 'OPTIONAL',
                    $columns,
                ),
            ),
            array_merge(['Column'], EmployeeBulkImportTemplate::columnLabels()),
            array_merge(['Example'], [
                'Asha Patil',
                '9876543210',
                'asha@example.com',
                'Employee',
                'E001',
                '2026-07-11',
                '25000',
                '300',
                'Actual Expense',
                '500',
                'No',
                '',
                '234567890123',
                'ABCDE1234F',
                'Test Bank',
                '123456789012',
                'TEST0123456',
                'Active',
            ]),
        ];
    }

    public function title(): string
    {
        return 'Employees';
    }
}
