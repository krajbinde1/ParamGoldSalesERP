<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CleanupOrphanEmployeeUsers
{
    /**
     * Remove login users that are not linked to an active employee.
     * Preserves admin users without a login_id and active employee accounts.
     */
    public function execute(?string $email = null, ?string $loginId = null): int
    {
        $removed = 0;

        $query = User::query();

        if ($email !== null || $loginId !== null) {
            $query->where(function ($builder) use ($email, $loginId): void {
                if ($email !== null) {
                    $builder->orWhere('email', $email);
                }

                if ($loginId !== null) {
                    $builder->orWhere('login_id', $loginId);
                }
            });
        } else {
            $query->whereNotNull('login_id');
        }

        $query->get()->each(function (User $user) use (&$removed): void {
            if ($this->isActiveEmployeeUser($user)) {
                return;
            }

            DB::transaction(function () use ($user): void {
                $user->tokens()->delete();
                $user->delete();
            });

            $removed++;
        });

        return $removed;
    }

    private function isActiveEmployeeUser(User $user): bool
    {
        if ($user->employee_id === null) {
            return false;
        }

        return Employee::query()->whereKey($user->employee_id)->exists();
    }
}
