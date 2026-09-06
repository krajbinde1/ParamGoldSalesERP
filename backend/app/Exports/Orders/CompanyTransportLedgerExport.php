<?php

namespace App\Exports\Orders;

use App\Models\CompanyTransportLedgerEntry;
use App\Services\Orders\CompanyTransportLedgerService;
use App\Support\IndianCurrency;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

final class CompanyTransportLedgerExport implements FromGenerator, ShouldAutoSize, WithCustomStartCell, WithEvents, WithHeadings, WithTitle
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(private readonly array $filters) {}

    public function generator(): \Generator
    {
        $service = app(CompanyTransportLedgerService::class);
        foreach ($service->ledgerRows($this->filters) as $entry) {
            yield [
                $entry->transaction_date?->format('d-m-Y'),
                $entry->particulars,
                $entry->order_no,
                $entry->transportTypeLabel(),
                $entry->vehicle_number,
                (float) $entry->debit_amount > 0.004 ? (float) $entry->debit_amount : null,
                (float) $entry->credit_amount > 0.004 ? (float) $entry->credit_amount : null,
                $entry->getAttribute('running_balance'),
                $entry->enteredBy?->name,
                $entry->entered_by_role,
                $entry->remark,
            ];
        }

        $summary = $service->summary($this->filters);
        yield ['', 'Total Transport Collected', '', '', '', '', $summary['total_collected'], '', '', '', ''];
        yield ['', 'Total Transport Expense', '', '', '', $summary['total_expense'], '', '', '', '', ''];
        yield ['', 'Current Balance', '', '', '', '', '', $summary['current_balance'], '', '', ''];
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Date',
            'Particulars',
            'Order No.',
            'Transport Type',
            'Vehicle No.',
            'Debit',
            'Credit',
            'Running Balance',
            'Entered By',
            'Role',
            'Remark',
        ];
    }

    public function startCell(): string
    {
        return 'A5';
    }

    public function title(): string
    {
        return 'Company Transport Ledger';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $summary = app(CompanyTransportLedgerService::class)->summary($this->filters);
                $sheet = $event->sheet->getDelegate();
                $sheet->setCellValue('A1', (string) config('app.name', 'ParamGold ERP'));
                $sheet->setCellValue('A2', 'Company Transport Ledger');
                $sheet->setCellValue(
                    'A3',
                    'Collected '.IndianCurrency::formatExact($summary['total_collected'])
                    .'  |  Expense '.IndianCurrency::formatExact($summary['total_expense'])
                    .'  |  Balance '.IndianCurrency::formatExact($summary['current_balance']),
                );
                $sheet->getStyle('A1:A2')->getFont()->setBold(true);
                $sheet->getStyle('A5:K5')->getFont()->setBold(true);
                $sheet->getStyle('A5:K5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
                $sheet->getStyle('F:H')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            },
        ];
    }
}
