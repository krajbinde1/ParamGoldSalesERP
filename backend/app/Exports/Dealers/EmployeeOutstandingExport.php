<?php

namespace App\Exports\Dealers;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

final class EmployeeOutstandingExport implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
{
    /**
     * @param  array{
     *     employee_name: string,
     *     employee_code: string|null,
     *     total: float,
     *     rows: list<array{employee_name: string, dealer_code: string, dealer_name: string, village: string, outstanding: float}>
     * }  $payload
     */
    public function __construct(
        private readonly array $payload,
        private readonly string $generatedAt,
    ) {}

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Employee Name',
            'Dealer Code',
            'Dealer Name',
            'Village',
            'Outstanding Amount',
        ];
    }

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        $rows = [];

        foreach ($this->payload['rows'] as $row) {
            $rows[] = [
                $row['employee_name'],
                $row['dealer_code'],
                $row['dealer_name'],
                $row['village'],
                $row['outstanding'],
            ];
        }

        $rows[] = [
            '',
            '',
            '',
            'Total Outstanding',
            $this->payload['total'],
        ];

        return $rows;
    }

    public function title(): string
    {
        return 'Outstanding';
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $employeeLabel = $this->payload['employee_name'];
                if (filled($this->payload['employee_code'])) {
                    $employeeLabel .= ' ('.$this->payload['employee_code'].')';
                }

                $sheet->insertNewRowBefore(1, 3);
                $sheet->setCellValue('A1', 'Total Outstanding');
                $sheet->setCellValue('A2', 'Employee: '.$employeeLabel);
                $sheet->setCellValue('A3', 'Generated on: '.$this->generatedAt);
                $sheet->mergeCells('A1:E1');
                $sheet->mergeCells('A2:E2');
                $sheet->mergeCells('A3:E3');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A2:A3')->getFont()->setItalic(true)->setSize(10);
                $sheet->getStyle('A4:E4')->getFont()->setBold(true);

                $highestRow = $sheet->getHighestRow();
                $sheet->getStyle('E5:E'.$highestRow)
                    ->getNumberFormat()
                    ->setFormatCode('"₹"#,##0.00');
                $sheet->getStyle('E5:E'.$highestRow)
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                $sheet->getStyle('A'.$highestRow.':E'.$highestRow)->getFont()->setBold(true);
            },
        ];
    }
}
