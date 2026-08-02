<?php

namespace App\Services\Inventory\BulkImport;

final class InventoryBulkImportResult
{
    /**
     * @param  list<InventoryBulkImportRowError>  $errors
     * @param  list<array<string, mixed>>  $mappings
     */
    public function __construct(
        public readonly int $totalRows,
        public readonly int $imported,
        public readonly int $skipped,
        public readonly int $failed,
        public readonly int $openingLedgerCreated,
        public readonly int $stockUpdated,
        public readonly array $errors,
        public readonly array $mappings = [],
    ) {}

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'opening_ledger_created' => $this->openingLedgerCreated,
            'stock_updated' => $this->stockUpdated,
        ];
    }
}
