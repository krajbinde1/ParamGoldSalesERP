<?php

namespace App\Policies;

use App\Models\Dealer;
use App\Models\User;
use App\Services\Dealers\DealerAccessService;

class DealerPolicy
{
    public function __construct(
        private readonly DealerAccessService $dealerAccess,
    ) {}

    public function viewAny(User $user): bool
    {
        if ($this->dealerAccess->canViewAll($user)) {
            return true;
        }

        if ($user->isManagerUser()) {
            return $user->employee_id !== null;
        }

        return $user->employee_id !== null;
    }

    public function view(User $user, Dealer $dealer): bool
    {
        return $this->dealerAccess->canAccessDealer($user, $dealer);
    }

    public function create(User $user): bool
    {
        return ! $user->isManagerUser();
    }

    public function update(User $user, Dealer $dealer): bool
    {
        return ! $user->isManagerUser();
    }

    public function delete(User $user, Dealer $dealer): bool
    {
        return ! $user->isManagerUser();
    }

    public function restore(User $user, Dealer $dealer): bool
    {
        return ! $user->isManagerUser();
    }

    public function forceDelete(User $user, Dealer $dealer): bool
    {
        return ! $user->isManagerUser();
    }

    public function deleteAny(User $user): bool
    {
        return ! $user->isManagerUser();
    }

    public function forceDeleteAny(User $user): bool
    {
        return ! $user->isManagerUser();
    }

    public function restoreAny(User $user): bool
    {
        return ! $user->isManagerUser();
    }
}
