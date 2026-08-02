<?php

namespace App\Exports\Inventory;

use App\Services\Inventory\StockItemLedgerResult;
use App\Services\Inventory\StockItemLedgerService;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Streams Tally-style item stock ledger rows (opening + transactions + closing totals).
 */
final class StockItemLedgerExport implements FromGenerator, ShouldAutoSize, WithCustomStartCell, WithEvents, WithHeadings, WithTitle
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly array $filters,
        private readonly StockItemLedgerResult $summary,
        private readonly string $companyName,
    ) {}

    public function generator(): \Generator
    {
        $service = app(StockItemLedgerService::class);

        foreach ($service->streamRows($this->filters) as $row) {
            $isOpening = ($row['row_type'] ?? '') === 'opening';

            yield [
                $isOpening
                    ? Carbon::parse((string) ($this->summary->header['from'] ?? $row['date']))->format('d-m-Y')
                    : ($row['date'] ?? ''),
                $row['particulars'] ?? '',
                $isOpening ? '' : ($row['voucher_no'] ?? ''),
                $isOpening ? null : ($row['inward_qty'] ?? null),
                $isOpening ? null : ($row['inward_value'] ?? null),
                $isOpening ? null : ($row['outward_qty'] ?? null),
                $isOpening ? null : ($row['outward_value'] ?? null),
                $row['closing_qty'],
                $row['closing_rate'],
                $row['closing_value'],
            ];
        }

        $t = $this->summary->totals;
        yield [
            '',
            'Closing Balance',
            '',
            $t['total_inward_qty'],
            $t['total_inward_value'],
            $t['total_outward_qty'],
            $t['total_outward_value'],
            $t['closing_qty'],
            $t['closing_rate'],
            $t['closing_value'],
        ];
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Date',
            'Particulars',
            'Voucher / Ref. No.',
            'Inward Quantity',
            'Inward Value',
            'Outward Quantity',
            'Outward Value',
            'Closing Quantity',
            'Average Purchase Rate',
            'Closing Value',
        ];
    }

    public function title(): string
    {
        return 'Item Stock Ledger';
    }

    public function startCell(): string
    {
        return 'A6';
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $h = $this->summary->header;

                $from = Carbon::parse((string) $h['from'])->format('d-m-Y');
                $to = Carbon::parse((string) $h['to'])->format('d-m-Y');

                $sheet->setCellValue('A1', $h['item_name']);
                $sheet->setCellValue('A2', 'Stock Ledger');
                $sheet->setCellValue('A3', $from.' to '.$to);
                $sheet->setCellValue('A4', 'Code: '.$h['item_code'].' | Unit: '.$h['unit']);

                foreach (['A1', 'A2', 'A3', 'A4'] as $cell) {
                    $sheet->mergeCells($cell.':J'.substr($cell, 1));
                }

                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);
                $sheet->getStyle('A6:J6')->getFont()->setBold(true);
                $sheet->getStyle('A6:J6')->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('E5E7EB');

                $highest = $sheet->getHighestRow();
                $sheet->getStyle('D7:J'.$highest)
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                foreach (['D', 'F', 'H'] as $col) {
                    $sheet->getStyle($col.'7:'.$col.$highest)
                        ->getNumberFormat()
                        ->setFormatCode('0.000');
                }
                foreach (['E', 'G', 'I', 'J'] as $col) {
                    $sheet->getStyle($col.'7:'.$col.$highest)
                        ->getNumberFormat()
                        ->setFormatCode('"₹"#,##0.00');
                }

                // Average purchase rate (I) already money-ish; keep 2dp via currency format above.
                $sheet->getStyle('A'.$highest.':J'.$highest)->getFont()->setBold(true);
            },
        ];
    }
}
