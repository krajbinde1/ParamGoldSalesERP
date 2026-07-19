<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UpdateEmployeeLoginId
{
    public function execute(Employee $employee, string $loginId, User $actor): User
    {
        if (! Gate::forUser($actor)->allows('updateLoginId', $employee)) {
            throw new AuthorizationException('You are not allowed to change this login ID.');
        }

        $user = $employee->user;

        if ($user === null) {
            throw ValidationException::withMessages([
                'login_id' => 'This employee does not have a login account.',
            ]);
        }

        $loginId = trim($loginId);

        $validator = Validator::make(
            ['login_id' => $loginId],
            ['login_id' => Employee::loginIdRules($user->id)],
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        if ($user->login_id === $loginId) {
            return $user;
        }

        return DB::transaction(function () use ($user, $loginId, $actor): User {
            $user->update([
                'login_id' => $loginId,
                'login_id_changed_by' => $actor->id,
                'login_id_changed_at' => now(),
            ]);

            return $user->fresh();
        });
    }
}
