<?php

namespace App\Exports\Inventory;

use App\Services\Inventory\InventoryReportResult;
use App\Services\Inventory\InventoryReportService;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Streams the filtered Unified Inventory Stock Report rows via generator
 * (chunk-friendly) — does not load all rows into memory, and does not
 * recalculate any stock/valuation figures already computed by the report.
 */
final class InventoryReportExport implements FromGenerator, ShouldAutoSize, WithCustomStartCell, WithEvents, WithHeadings, WithTitle
{
    public function __construct(
        private readonly InventoryReportResult $report,
        private readonly string $generatedAt,
    ) {}

    public function generator(): \Generator
    {
        foreach ($this->report->exportRows() as $row) {
            yield $this->formatExportRow($row);
        }
    }

    /**
     * @param  list<mixed>  $row
     * @return list<mixed>
     */
    protected function formatExportRow(array $row): array
    {
        foreach ($this->report->columns as $index => $column) {
            if (! array_key_exists($index, $row)) {
                continue;
            }

            $format = $column['format'];
            $value = $row[$index];

            if ($format === 'badge_stock') {
                $row[$index] = match ((string) $value) {
                    'out_of_stock' => 'Out of Stock',
                    'low_stock' => 'Low Stock',
                    'in_stock' => 'In Stock',
                    default => $value,
                };
            }
        }

        return $row;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->report->headingLabels();
    }

    public function title(): string
    {
        return mb_substr($this->report->title, 0, 31) ?: 'Report';
    }

    public function startCell(): string
    {
        return 'A4';
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $columnCount = max(count($this->report->columns), 1);
                $lastColumn = $this->columnLetter($columnCount);

                $sheet->setCellValue('A1', $this->report->title);
                $sheet->setCellValue('A2', 'Generated on: '.$this->generatedAt);
                $sheet->setCellValue(
                    'A3',
                    'Applied filters: '.(implode(' | ', $this->report->appliedFilterLabels) ?: 'None'),
                );

                $sheet->mergeCells("A1:{$lastColumn}1");
                $sheet->mergeCells("A2:{$lastColumn}2");
                $sheet->mergeCells("A3:{$lastColumn}3");

                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A2:A3')->getFont()->setItalic(true)->setSize(10);
                $sheet->getStyle("A4:{$lastColumn}4")->getFont()->setBold(true);

                $highestRow = $sheet->getHighestRow();

                foreach ($this->report->columns as $index => $column) {
                    $letter = $this->columnLetter($index + 1);
                    $format = $column['format'];

                    $sheet->getStyle("{$letter}4:{$letter}{$highestRow}")
                        ->getAlignment()
                        ->setHorizontal(in_array($format, ['qty', 'money', 'rate', 'integer'], true)
                            ? Alignment::HORIZONTAL_RIGHT
                            : Alignment::HORIZONTAL_LEFT);

                    if (in_array($format, ['money', 'rate'], true)) {
                        $sheet->getStyle("{$letter}5:{$letter}{$highestRow}")
                            ->getNumberFormat()
                            ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
                    } elseif ($format === 'qty') {
                        $sheet->getStyle("{$letter}5:{$letter}{$highestRow}")
                            ->getNumberFormat()
                            ->setFormatCode('#,##0.###');
                    }
                }

                $this->writeTotalsBlock($sheet, $highestRow, $lastColumn);
            },
        ];
    }

    /**
     * Bottom totals block: Raw Material / Packaging Material / Semi Finished /
     * Finished Product value + Grand Total, computed from the filtered dataset.
     */
    protected function writeTotalsBlock(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $highestRow, string $lastColumn): void
    {
        $breakdown = $this->report->footerBreakdownTotals();

        if ($breakdown === null) {
            return;
        }

        $labelCol = $this->columnLetter(max(1, count($this->report->columns) - 1));
        $valueCol = $this->columnLetter(count($this->report->columns));

        $rows = [
            'Raw Material Value' => $breakdown[InventoryReportService::TYPE_RAW_MATERIAL] ?? 0.0,
            'Packaging Material Value' => $breakdown[InventoryReportService::TYPE_PACKAGING_MATERIAL] ?? 0.0,
            'Semi Finished Value' => $breakdown[InventoryReportService::TYPE_SEMI_FINISHED] ?? 0.0,
            'Finished Product Value' => $breakdown[InventoryReportService::TYPE_FINISHED_PRODUCT] ?? 0.0,
        ];

        $grandTotal = array_sum($rows);
        $rows['Grand Total Stock Value'] = $grandTotal;

        $row = $highestRow + 2;

        foreach ($rows as $label => $value) {
            $sheet->setCellValue("{$labelCol}{$row}", $label);
            $sheet->setCellValue("{$valueCol}{$row}", $value);
            $sheet->getStyle("{$labelCol}{$row}")->getFont()->setBold($label === 'Grand Total Stock Value');
            $sheet->getStyle("{$valueCol}{$row}")->getFont()->setBold($label === 'Grand Total Stock Value');
            $sheet->getStyle("{$valueCol}{$row}")
                ->getNumberFormat()
                ->setFormatCode('"₹"#,##0.00');
            $row++;
        }
    }

    protected function columnLetter(int $index): string
    {
        $letter = '';

        while ($index > 0) {
            $modulo = ($index - 1) % 26;
            $letter = chr(65 + $modulo).$letter;
            $index = intdiv($index - $modulo, 26);
        }

        return $letter ?: 'A';
    }
}
