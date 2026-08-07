<?php

namespace App\Exports\Inventory;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;

/**
 * Pre-fills EVERY product from Sales Operations -> Products.
 *
 * Source: App\Models\Product (same SoftDeletes-aware list as ProductResource).
 * Does NOT use finished_products / inventory-only filters.
 */
final class FinishedGoodsOpeningStockTemplateExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
{
    private int $dataRowCount = 0;

    public function headings(): array
    {
        return [
            'Product Code',
            'Product Name',
            'Opening Stock Quantity',
            'Opening Stock Value',
            'Opening Stock Date',
        ];
    }

    public function collection(): Collection
    {
        // Exact same base model/query as Sales Operations -> Products (ProductResource):
        // Product::query() with SoftDeletes (excludes trashed by default). No extra filters.
        $products = Product::query()
            ->orderBy('product_name')
            ->orderBy('product_code')
            ->orderBy('id')
            ->get([
                'id',
                'product_code',
                'product_name',
            ]);

        $count = $products->count();
        $this->dataRowCount = $count;

        Log::info('Sales Products count: '.$count);

        return $products->map(static function (Product $product): array {
            return [
                (string) ($product->product_code ?? ''),
                (string) ($product->product_name ?? ''),
                '',
                '',
                '',
            ];
        })->values();
    }

    public function title(): string
    {
        return 'FG Opening Stock';
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                $highestRow = (int) $sheet->getHighestDataRow();
                $dataRows = max($highestRow - 1, 0);
                $this->dataRowCount = $dataRows;
                $lastDataRow = max($highestRow, 1);

                Log::info('Sales Products count: '.$dataRows.' (sheet written)');

                $sheet->getStyle('A1:E1')->getFont()->setBold(true);
                $sheet->getStyle('A1:E1')->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setRGB('E5E7EB');
                $sheet->freezePane('A2');

                $sheet->getStyle('A1:E'.$lastDataRow)
                    ->getProtection()
                    ->setLocked(Protection::PROTECTION_PROTECTED);

                if ($dataRows > 0) {
                    $sheet->getStyle("C2:E{$lastDataRow}")
                        ->getProtection()
                        ->setLocked(Protection::PROTECTION_UNPROTECTED);

                    $this->applyNumericValidation(
                        sheet: $sheet,
                        range: "C2:C{$lastDataRow}",
                        errorTitle: 'Invalid Quantity',
                        errorMessage: 'Opening Stock Quantity must be a number (0 or greater).',
                    );
                    $this->applyNumericValidation(
                        sheet: $sheet,
                        range: "D2:D{$lastDataRow}",
                        errorTitle: 'Invalid Value',
                        errorMessage: 'Opening Stock Value must be a number (0 or greater).',
                    );

                    $sheet->getStyle("E2:E{$lastDataRow}")
                        ->getNumberFormat()
                        ->setFormatCode('DD-MM-YYYY');
                    $sheet->getStyle("E2:E{$lastDataRow}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_LEFT);

                    $this->applyDateValidation(
                        sheet: $sheet,
                        range: "E2:E{$lastDataRow}",
                    );

                    $sheet->getStyle("C2:D{$lastDataRow}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }

                $protection = $sheet->getProtection();
                $protection->setSheet(true);
                $protection->setSort(false);
                $protection->setInsertRows(false);
                $protection->setInsertColumns(false);
                $protection->setDeleteRows(false);
                $protection->setDeleteColumns(false);
                $protection->setFormatCells(false);
                $protection->setFormatColumns(false);
                $protection->setFormatRows(false);
            },
        ];
    }

    private function applyNumericValidation(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        string $range,
        string $errorTitle,
        string $errorMessage,
    ): void {
        $validation = $sheet->getCell(explode(':', $range)[0])->getDataValidation();
        $validation->setType(DataValidation::TYPE_DECIMAL);
        $validation->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL);
        $validation->setFormula1('0');
        $validation->setAllowBlank(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowInputMessage(true);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setErrorTitle($errorTitle);
        $validation->setError($errorMessage);
        $validation->setPromptTitle('Numeric only');
        $validation->setPrompt($errorMessage);
        $validation->setSqref($range);
    }

    private function applyDateValidation(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        string $range,
    ): void {
        $validation = $sheet->getCell(explode(':', $range)[0])->getDataValidation();
        $validation->setType(DataValidation::TYPE_DATE);
        $validation->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL);
        $validation->setFormula1('1');
        $validation->setAllowBlank(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowInputMessage(true);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setErrorTitle('Invalid Date');
        $validation->setError('Opening Stock Date must be a valid date in DD-MM-YYYY format.');
        $validation->setPromptTitle('Date (DD-MM-YYYY)');
        $validation->setPrompt('Enter date as DD-MM-YYYY.');
        $validation->setSqref($range);
    }
}
