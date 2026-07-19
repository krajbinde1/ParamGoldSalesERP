<?php

namespace App\Services\Products;

final class ProductBulkImportRowError
{
    /**
     * @param  array<string, mixed>  $rowData
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly array $rowData,
        public readonly string $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toReportRow(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'product_name' => (string) ($this->rowData['product_name'] ?? ''),
            'product_code' => (string) ($this->rowData['product_code'] ?? ''),
            'dealer_price' => (string) ($this->rowData['dealer_price'] ?? ''),
            'nos_per_case' => (string) ($this->rowData['nos_per_case'] ?? ''),
            'gst_percentage' => (string) ($this->rowData['gst_percentage'] ?? ''),
            'status' => (string) ($this->rowData['status'] ?? ''),
            'error' => $this->reason,
        ];
    }
}
