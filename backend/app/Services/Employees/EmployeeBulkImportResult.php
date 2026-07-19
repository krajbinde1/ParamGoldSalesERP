<?php

namespace App\Services\Employees;

final class EmployeeBulkImportResult
{
    /** @param  list<EmployeeBulkImportRowError>  $errors */
    public function __construct(
        public readonly int $totalRows,
        public readonly int $created,
        public readonly int $updated,
        public readonly array $errors,
    ) {}

    public function failed(): int
    {
        return count($this->errors);
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'created' => $this->created,
            'updated' => $this->updated,
            'failed' => $this->failed(),
        ];
    }
}
