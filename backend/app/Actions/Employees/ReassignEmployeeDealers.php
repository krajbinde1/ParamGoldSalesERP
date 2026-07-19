<?php

namespace App\Actions\Employees;

use App\Models\Dealer;
use App\Models\Employee;
use App\Support\EmployeeCodeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReassignEmployeeDealers
{
    public function execute(Employee $fromEmployee, Employee $toEmployee): int
    {
        if ((new EmployeeCodeResolver)->resolveAssignableEmployee($toEmployee->employee_code) === null) {
            throw ValidationException::withMessages([
                'target_employee_id' => 'Select an active employee with the Employee login role.',
            ]);
        }

        if ($fromEmployee->is($toEmployee)) {
            throw ValidationException::withMessages([
                'target_employee_id' => 'Select a different employee for reassignment.',
            ]);
        }

        return DB::transaction(fn (): int => Dealer::query()
            ->where('assigned_employee_id', $fromEmployee->id)
            ->update(['assigned_employee_id' => $toEmployee->id]));
    }
}
