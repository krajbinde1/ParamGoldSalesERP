<?php

namespace App\Services\PaymentRequests;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class PaymentRequestApproverResolver
{
    public function firstApproverUserId(): ?int
    {
        return $this->resolveUserId(
            configuredId: config('payment_requests.first_approver_user_id'),
            name: (string) config('payment_requests.first_approver_name', 'Krishna Rajbinde'),
            cacheKey: 'payment_request_first_approver_user_id_v3',
        );
    }

    public function secondApproverUserId(): ?int
    {
        return $this->resolveUserId(
            configuredId: config('payment_requests.second_approver_user_id'),
            name: (string) config('payment_requests.second_approver_name', 'Bhagwan Kakde'),
            cacheKey: 'payment_request_second_approver_user_id_v3',
        );
    }

    public function firstApprover(): ?User
    {
        $id = $this->firstApproverUserId();

        return $id ? User::query()->find($id) : null;
    }

    public function secondApprover(): ?User
    {
        $id = $this->secondApproverUserId();

        return $id ? User::query()->find($id) : null;
    }

    public function isFirstApprover(User $user): bool
    {
        $id = $this->firstApproverUserId();

        return $id !== null && (int) $user->id === $id;
    }

    public function isSecondApprover(User $user): bool
    {
        $id = $this->secondApproverUserId();

        return $id !== null && (int) $user->id === $id;
    }

    public function isConfiguredApprover(User $user): bool
    {
        return $this->isFirstApprover($user) || $this->isSecondApprover($user);
    }

    public function firstApproverDisplayName(): string
    {
        return $this->firstApprover()?->name
            ?: (string) config('payment_requests.first_approver_name', 'Krishna Rajbinde');
    }

    public function secondApproverDisplayName(): string
    {
        return $this->secondApprover()?->name
            ?: (string) config('payment_requests.second_approver_name', 'Bhagwan Kakde');
    }

    private function resolveUserId(mixed $configuredId, string $name, string $cacheKey): ?int
    {
        if (filled($configuredId) && (int) $configuredId > 0) {
            return (int) $configuredId;
        }

        return Cache::remember($cacheKey, 300, function () use ($name): ?int {
            $normalized = mb_strtolower(trim($name));
            if ($normalized === '') {
                return null;
            }

            // LOGIN ROLE is authoritative — designation/job title must not decide routing.
            $byUserName = User::query()
                ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
                ->where('role', UserRole::Director->value)
                ->value('id');
            if ($byUserName) {
                return (int) $byUserName;
            }

            $employeeId = Employee::query()
                ->whereRaw('LOWER(TRIM(full_name)) = ?', [$normalized])
                ->value('id');
            if (! $employeeId) {
                return null;
            }

            $userId = User::query()
                ->where('employee_id', $employeeId)
                ->where('role', UserRole::Director->value)
                ->value('id');

            return $userId ? (int) $userId : null;
        });
    }
}
