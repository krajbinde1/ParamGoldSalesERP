<?php

namespace App\Services\Employees;

final class EmployeeBulkImportRowError
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
