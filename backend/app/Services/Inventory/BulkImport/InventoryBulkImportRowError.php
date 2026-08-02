<?php

namespace App\Services\Inventory\BulkImport;

final class InventoryBulkImportRowError
{
    /**
     * @param  array<string, mixed>  $rowData
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly array $rowData,
        public readonly string $reason,
    ) {}
}
