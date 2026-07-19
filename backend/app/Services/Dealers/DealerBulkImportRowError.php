<?php

namespace App\Services\Dealers;

final class DealerBulkImportRowError
{
    public function __construct(
        public readonly int $rowNumber,
        public readonly string $firmName,
        public readonly string $assignedEmployeeCode,
        public readonly string $reason,
    ) {}

    /**
     * @return array{row:int,firm_name:string,assigned_employee_code:string,reason:string}
     */
    public function toArray(): array
    {
        return [
            'row' => $this->rowNumber,
            'firm_name' => $this->firmName,
            'assigned_employee_code' => $this->assignedEmployeeCode,
            'reason' => $this->reason,
        ];
    }
}
