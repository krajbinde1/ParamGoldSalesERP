<?php

namespace App\Actions\Employees;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class ProvisionEmployeeUserAccount
{
    public function execute(Employee $employee, ?string $role = null, ?string $loginId = null): User
    {
        $existingUser = $employee->user;

        if ($existingUser !== null) {
            return $existingUser;
        }

        app(CleanupOrphanEmployeeUsers::class)->execute(
            email: $employee->email,
            loginId: $loginId ?? $employee->mobile,
        );

        $temporaryPassword = substr($employee->mobile, -4);
        $roleValue = UserRole::tryFromMixed($role ?? UserRole::Employee->value)->value;

        return User::query()->create([
            'employee_id' => $employee->id,
            'name' => $employee->full_name,
            'email' => $employee->email
                ?? strtolower($employee->employee_code).'@employees.paramgold.local',
            'login_id' => $loginId ?? $employee->mobile,
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
            'role' => $roleValue,
        ]);
    }
}
