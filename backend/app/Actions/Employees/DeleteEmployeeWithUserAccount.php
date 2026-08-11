<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use App\Services\SafeDelete\SafeDeleteGuard;
use Illuminate\Support\Facades\DB;

final class DeleteEmployeeWithUserAccount
{
    public function execute(Employee $employee, bool $force = false): ?bool
    {
        // Central Safe Delete policy — blocks when attendance, routes, orders, etc. exist.
        app(SafeDeleteGuard::class)->assertCanDelete($employee);

        return DB::transaction(function () use ($employee, $force): ?bool {
            $mobile = $employee->mobile;
            $email = $employee->email;

            $this->removeLinkedUserAccount($employee);
            $this->releaseUniqueIdentifiers($employee);

            // Events already validated above; avoid double-running cleanup side effects.
            $deleted = Employee::withoutEvents(function () use ($employee, $force): ?bool {
                return $force ? $employee->forceDelete() : $employee->delete();
            });

            app(CleanupOrphanEmployeeUsers::class)->execute(
                email: filled($email) ? $email : null,
                loginId: filled($mobile) ? $mobile : null,
            );

            return $deleted;
        });
    }

    public function removeLinkedUserAccount(Employee $employee): void
    {
        User::query()
            ->where(function ($query) use ($employee): void {
                $query->where('employee_id', $employee->id);

                if (filled($employee->mobile)) {
                    $query->orWhere('login_id', $employee->mobile);
                }

                if (filled($employee->email)) {
                    $query->orWhere('email', $employee->email);
                }
            })
            ->get()
            ->each(function (User $user): void {
                $user->tokens()->delete();
                $user->delete();
            });
    }

    public function releaseUniqueIdentifiers(Employee $employee): void
    {
        $id = (int) $employee->id;

        $employee->forceFill([
            'mobile' => '9'.str_pad((string) $id, 9, '0', STR_PAD_LEFT),
            'email' => filled($employee->email)
                ? "deleted+{$id}@released.paramgold.local"
                : null,
            'aadhaar_number' => '9'.str_pad((string) $id, 11, '0', STR_PAD_LEFT),
            'pan_number' => 'DEL'.str_pad((string) $id, 7, '0', STR_PAD_LEFT),
        ])->saveQuietly();
    }
}
