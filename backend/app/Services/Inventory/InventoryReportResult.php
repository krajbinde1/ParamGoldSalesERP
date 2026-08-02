<?php

namespace App\Services\Inventory;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Immutable view-model for the Unified Inventory Stock Report render/export.
 * Queries are builders only — callers paginate or cursor/chunk; rows are never fully loaded here.
 */
final class InventoryReportResult
{
    /**
     * @param  list<array{key: string, label: string, align: string, format: string, sortable: string|false}>  $columns
     * @param  list<array<string, mixed>>  $summaryCards
     * @param  list<string>  $appliedFilterLabels
     * @param  EloquentBuilder|QueryBuilder  $query
     * @param  callable(object, int): list<mixed>  $rowMapper
     * @param  (callable(EloquentBuilder|QueryBuilder): float|null)|null  $footerStockValue
     * @param  (callable(EloquentBuilder|QueryBuilder): array<string, float>)|null  $footerBreakdown
     */
    public function __construct(
        public readonly string $title,
        public readonly string $filenameStem,
        public readonly array $columns,
        public readonly array $summaryCards,
        public readonly array $appliedFilterLabels,
        public readonly EloquentBuilder|QueryBuilder $query,
        public readonly mixed $rowMapper,
        public readonly ?string $defaultSort,
        public readonly string $defaultSortDirection = 'asc',
        public readonly mixed $footerStockValue = null,
        public readonly mixed $footerBreakdown = null,
    ) {}

    /**
     * @return list<string>
     */
    public function headingLabels(): array
    {
        return array_map(static fn (array $column): string => $column['label'], $this->columns);
    }

    public function paginate(int $perPage, string $sortBy, string $sortDirection, int $page = 1): LengthAwarePaginator
    {
        $query = clone $this->query;
        $sortable = collect($this->columns)
            ->filter(fn (array $column): bool => filled($column['sortable'] ?? false))
            ->mapWithKeys(fn (array $column): array => [$column['key'] => $column['sortable']])
            ->all();

        $sortColumn = $sortable[$sortBy] ?? ($this->defaultSort ?: reset($sortable) ?: null);
        $direction = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

        if (is_string($sortColumn) && $sortColumn !== '') {
            $query->orderBy($sortColumn, $direction);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Stream mapped export rows without loading the full result set.
     *
     * @return \Generator<int, list<mixed>>
     */
    public function exportRows(): \Generator
    {
        $query = clone $this->query;
        $sortColumn = $this->defaultSort;

        if (is_string($sortColumn) && $sortColumn !== '') {
            $query->orderBy($sortColumn, $this->defaultSortDirection);
        }

        $index = 0;

        if ($query instanceof EloquentBuilder) {
            foreach ($query->lazy(500) as $record) {
                $index++;
                yield ($this->rowMapper)($record, $index);
            }

            return;
        }

        foreach ($query->cursor() as $record) {
            $index++;
            yield ($this->rowMapper)($record, $index);
        }
    }

    public function totalStockValueFooter(): ?float
    {
        if (! is_callable($this->footerStockValue)) {
            return null;
        }

        return ($this->footerStockValue)(clone $this->query);
    }

    /**
     * Per inventory-type stock value totals for the currently filtered dataset.
     *
     * @return array<string, float>|null
     */
    public function footerBreakdownTotals(): ?array
    {
        if (! is_callable($this->footerBreakdown)) {
            return null;
        }

        return ($this->footerBreakdown)(clone $this->query);
    }

    /**
     * @param  Collection<int, object>  $records
     * @return list<array{cells: list<array{value: mixed, align: string, format: string, raw: mixed}>}>
     */
    public function mapPageRecords(Collection $records, int $pageStartIndex): array
    {
        $mapped = [];
        $index = $pageStartIndex;

        foreach ($records as $record) {
            $index++;
            $values = ($this->rowMapper)($record, $index);
            $cells = [];

            foreach ($this->columns as $offset => $column) {
                $cells[] = [
                    'value' => $values[$offset] ?? null,
                    'align' => $column['align'],
                    'format' => $column['format'],
                    'raw' => $values[$offset] ?? null,
                ];
            }

            $mapped[] = ['cells' => $cells];
        }

        return $mapped;
    }
}
