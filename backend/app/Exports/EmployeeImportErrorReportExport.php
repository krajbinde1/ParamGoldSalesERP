<?php

namespace App\Exports;

use App\Services\Employees\EmployeeBulkImportRowError;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

final class EmployeeImportErrorReportExport implements FromArray, WithHeadings, WithTitle
{
    /** @param  list<EmployeeBulkImportRowError>  $errors */
    public function __construct(
        private readonly array $errors,
    ) {}

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        return array_map(
            fn (EmployeeBulkImportRowError $error): array => [
                $error->rowNumber,
                (string) ($error->rowData['full_name'] ?? ''),
                (string) ($error->rowData['mobile'] ?? ''),
                (string) ($error->rowData['email'] ?? ''),
                (string) ($error->rowData['role'] ?? ''),
                (string) ($error->rowData['aadhaar_number'] ?? ''),
                (string) ($error->rowData['pan_number'] ?? ''),
                $error->reason,
            ],
            $this->errors,
        );
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Row Number',
            'Employee Name',
            'Mobile Number',
            'Email',
            'Role',
            'Aadhaar Number',
            'PAN Number',
            'Error',
        ];
    }

    public function title(): string
    {
        return 'Failed Rows';
    }
}
