<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\TaDaClaim;
use App\Models\User;
use App\Services\Orders\ManagerOrderAccessService;

class TaDaClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            UserRole::Employee,
            UserRole::Manager,
            UserRole::Director,
        ]);
    }

    public function view(User $user, TaDaClaim $claim): bool
    {
        return match ($user->roleEnum()) {
            UserRole::Employee => $claim->employee_id === $user->employee_id,
            UserRole::Manager => $this->managerOwnsClaim($user, $claim),
            UserRole::Director => true,
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Employee);
    }

    public function approve(User $user, TaDaClaim $claim): bool
    {
        return $user->hasRole(UserRole::Manager)
            && $claim->canApprove()
            && $this->managerOwnsClaim($user, $claim);
    }

    public function reject(User $user, TaDaClaim $claim): bool
    {
        return $user->hasRole(UserRole::Manager)
            && $claim->canReject()
            && $this->managerOwnsClaim($user, $claim);
    }

    public function markPaid(User $user, TaDaClaim $claim): bool
    {
        return false;
    }

    private function managerOwnsClaim(User $user, TaDaClaim $claim): bool
    {
        $reportIds = app(ManagerOrderAccessService::class)->directReportEmployeeIds($user);

        return in_array((int) $claim->employee_id, $reportIds, true);
    }
}
