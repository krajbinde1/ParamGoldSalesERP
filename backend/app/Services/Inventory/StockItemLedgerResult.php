<?php

namespace App\Services\Inventory;

/**
 * Read-only Tally-style item stock ledger result.
 */
final class StockItemLedgerResult
{
    /**
     * @param  array{
     *     item_type: string,
     *     item_type_label: string,
     *     item_id: int,
     *     item_name: string,
     *     item_code: string,
     *     category: string,
     *     unit: string,
     *     current_average_rate: float,
     *     from: string,
     *     to: string,
     *     opening_qty: float,
     *     opening_value: float,
     *     opening_rate: float,
     *     closing_qty: float,
     *     closing_value: float,
     *     closing_rate: float,
     *     warning: ?string
     * }  $header
     * @param  list<array<string, mixed>>  $rows
     * @param  array{
     *     total_inward_qty: float,
     *     total_inward_value: float,
     *     total_outward_qty: float,
     *     total_outward_value: float,
     *     closing_qty: float,
     *     closing_rate: float,
     *     closing_value: float
     * }  $totals
     */
    public function __construct(
        public readonly array $header,
        public readonly array $rows,
        public readonly array $totals,
        public readonly int $totalTransactionCount,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->totalTransactionCount / max(1, $this->perPage)));
    }

    public function hasSelection(): bool
    {
        return ($this->header['item_id'] ?? 0) > 0;
    }
}
