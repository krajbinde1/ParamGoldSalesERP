<?php

namespace App\Exports\Dealers;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

final class TallyBulkNotMatchedExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        private readonly array $rows,
    ) {}

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Employee Code',
            'Employee Name',
            'File Name',
            'Tally Dealer Name',
            'Reason',
            'Suggested Dealer',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function array(): array
    {
        return array_map(
            fn (array $row): array => [
                (string) ($row['employee_code'] ?? ''),
                (string) ($row['employee_name'] ?? ''),
                (string) ($row['file_name'] ?? ''),
                (string) ($row['detected_dealer'] ?? ''),
                (string) ($row['reason'] ?? ''),
                (string) ($row['suggested_dealer'] ?? ''),
            ],
            $this->rows,
        );
    }

    public function title(): string
    {
        return 'Not Matched';
    }
}
