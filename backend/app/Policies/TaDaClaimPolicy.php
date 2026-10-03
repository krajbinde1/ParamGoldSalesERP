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
            UserRole::Employee => (int) $claim->employee_id === (int) $user->employee_id,
            UserRole::Manager => $this->isOwnClaim($user, $claim) || $this->managerOwnsClaim($user, $claim),
            UserRole::Director => true,
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Employee)
            || ($user->hasRole(UserRole::Manager) && $user->employee_id !== null);
    }

    public function approve(User $user, TaDaClaim $claim): bool
    {
        if ($user->hasRole(UserRole::Director)) {
            return $claim->canApprove() && $claim->requiresDirectorApproval();
        }

        return $user->hasRole(UserRole::Manager)
            && $claim->canApprove()
            && ! $claim->requiresDirectorApproval()
            && ! $this->isOwnClaim($user, $claim)
            && $this->managerOwnsClaim($user, $claim);
    }

    public function reject(User $user, TaDaClaim $claim): bool
    {
        if ($user->hasRole(UserRole::Director)) {
            return $claim->canReject() && $claim->requiresDirectorApproval();
        }

        return $user->hasRole(UserRole::Manager)
            && $claim->canReject()
            && ! $claim->requiresDirectorApproval()
            && ! $this->isOwnClaim($user, $claim)
            && $this->managerOwnsClaim($user, $claim);
    }

    public function markPaid(User $user, TaDaClaim $claim): bool
    {
        return false;
    }

    private function managerOwnsClaim(User $user, TaDaClaim $claim): bool
    {
        if ($claim->requiresDirectorApproval()) {
            return false;
        }

        $reportIds = app(ManagerOrderAccessService::class)->directReportEmployeeIds($user);

        return in_array((int) $claim->employee_id, $reportIds, true);
    }

    private function isOwnClaim(User $user, TaDaClaim $claim): bool
    {
        return $user->employee_id !== null
            && (int) $claim->employee_id === (int) $user->employee_id;
    }
}
