<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;

class EmployeeLoginAccessPolicy
{
    public function updateLoginId(User $actor, Employee $employee): bool
    {
        return $this->isAdminOrDirector($actor) && $employee->user !== null;
    }

    public function resetPassword(User $actor, Employee $employee): bool
    {
        $targetUser = $employee->user;

        if ($targetUser === null) {
            return false;
        }

        if ($this->isAdminOrDirector($actor)) {
            return true;
        }

        if ($actor->hasRole(UserRole::Manager)) {
            return ! $targetUser->hasAnyRole([
                UserRole::Director,
                UserRole::Manager,
            ]);
        }

        return false;
    }

    private function isAdminOrDirector(User $actor): bool
    {
        return $actor->employee_id === null || $actor->hasRole(UserRole::Director);
    }
}
