<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

final class EmployeeCodeResolver
{
    /**
     * Resolve an active employee from a bulk-import employee code (e.g. E001).
     */
    public function resolveActiveEmployee(?string $employeeCode): ?Employee
    {
        return Employee::resolveActiveByCode($employeeCode);
    }

    public function resolveActiveEmployeeId(?string $employeeCode): ?int
    {
        return $this->resolveActiveEmployee($employeeCode)?->id;
    }

    /**
     * Resolve an active employee eligible for dealer assignment (Employee login role only).
     */
    public function resolveAssignableEmployee(?string $employeeCode): ?Employee
    {
        $employee = Employee::resolveByCode($employeeCode);

        if ($employee === null || ! $employee->status || $employee->trashed()) {
            return null;
        }

        if ($employee->user?->role !== UserRole::Employee->value) {
            return null;
        }

        return $employee;
    }

    public function resolveAssignableEmployeeId(?string $employeeCode): ?int
    {
        return $this->resolveAssignableEmployee($employeeCode)?->id;
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public static function scopeAssignableEmployees(Builder $query): Builder
    {
        return $query
            ->where('status', true)
            ->whereHas('user', fn (Builder $userQuery): Builder => $userQuery->where('role', UserRole::Employee->value));
    }
}
