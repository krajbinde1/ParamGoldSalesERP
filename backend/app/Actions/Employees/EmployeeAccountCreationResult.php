<?php

namespace App\Actions\Employees;

use App\Models\Employee;

final readonly class EmployeeAccountCreationResult
{
    public function __construct(
        public Employee $employee,
        public string $loginId,
        public string $temporaryPassword,
    ) {}
}
