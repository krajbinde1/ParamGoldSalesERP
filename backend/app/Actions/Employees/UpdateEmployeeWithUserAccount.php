<?php

namespace App\Actions\Employees;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UpdateEmployeeWithUserAccount
{
    public function execute(Employee $employee, array $employeeData, ?User $actor = null): Employee
    {
        unset($employeeData['login_id'], $employeeData['employee_code']);

        $employeeData = Employee::normalizeCreationData($employeeData);

        $validator = Validator::make($employeeData, Employee::creationRules($employee));
        Employee::validateTravelKmLimits($validator);
        Employee::validateCompanyCard($validator);
        Employee::validateReportingManager($validator, $employee);
        $validator->validate();

        return DB::transaction(function () use ($employee, $employeeData): Employee {
            $role = isset($employeeData['role'])
                ? UserRole::tryFromMixed($employeeData['role'])->value
                : null;
            unset($employeeData['role']);

            $employee->update($employeeData);

            $userUpdates = [
                'name' => $employee->full_name,
                'email' => $employee->email
                    ?? strtolower($employee->employee_code).'@employees.paramgold.local',
                'login_id' => $employee->mobile,
            ];

            if ($role !== null) {
                $userUpdates['role'] = $role;
            }

            $employee->user?->update($userUpdates);

            return $employee->refresh();
        });
    }
}
