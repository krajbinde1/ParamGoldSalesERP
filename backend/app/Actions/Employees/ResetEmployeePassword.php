<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ResetEmployeePassword
{
    public const MODE_MOBILE_LAST_FOUR = 'mobile_last_four';

    public const MODE_CUSTOM = 'custom';

    /**
     * @return array{login_id: string, temporary_password: string}
     */
    public function execute(
        Employee $employee,
        User $actor,
        string $mode,
        ?string $customPassword = null,
        ?string $customPasswordConfirmation = null,
    ): array {
        if (! Gate::forUser($actor)->allows('resetPassword', $employee)) {
            throw new AuthorizationException('You are not allowed to reset this password.');
        }

        $user = $employee->user;

        if ($user === null) {
            throw ValidationException::withMessages([
                'password' => 'This employee does not have a login account.',
            ]);
        }

        $temporaryPassword = match ($mode) {
            self::MODE_MOBILE_LAST_FOUR => substr($employee->mobile, -4),
            self::MODE_CUSTOM => $this->validatedCustomPassword($customPassword, $customPasswordConfirmation),
            default => throw ValidationException::withMessages([
                'reset_mode' => 'Select a valid password reset option.',
            ]),
        };

        DB::transaction(function () use ($user, $temporaryPassword, $actor): void {
            $user->tokens()->delete();

            $user->update([
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
                'password_reset_by' => $actor->id,
                'password_reset_at' => now(),
            ]);
        });

        return [
            'login_id' => $user->fresh()->login_id,
            'temporary_password' => $temporaryPassword,
        ];
    }

    private function validatedCustomPassword(?string $password, ?string $confirmation): string
    {
        $validator = Validator::make(
            [
                'custom_password' => $password,
                'custom_password_confirmation' => $confirmation,
            ],
            [
                'custom_password' => ['required', 'string', 'min:4', 'confirmed'],
            ],
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return (string) $password;
    }
}
