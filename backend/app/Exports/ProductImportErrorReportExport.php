<?php

namespace App\Exports;

use App\Services\Products\ProductBulkImportRowError;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

final class ProductImportErrorReportExport implements FromArray, WithHeadings, WithTitle
{
    /** @param  list<ProductBulkImportRowError>  $errors */
    public function __construct(
        private readonly array $errors,
    ) {}

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        return array_map(
            fn (ProductBulkImportRowError $error): array => [
                $error->rowNumber,
                (string) ($error->rowData['product_name'] ?? ''),
                (string) ($error->rowData['product_code'] ?? ''),
                (string) ($error->rowData['dealer_price'] ?? ''),
                (string) ($error->rowData['nos_per_case'] ?? ''),
                (string) ($error->rowData['gst_percentage'] ?? ''),
                (string) ($error->rowData['status'] ?? ''),
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
            'Product Name',
            'Product Code',
            'Dealer Price',
            'Nos Per Case',
            'GST %',
            'Status',
            'Error',
        ];
    }

    public function title(): string
    {
        return 'Failed Rows';
    }
}
