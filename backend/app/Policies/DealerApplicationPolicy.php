<?php

namespace App\Policies;

use App\Models\DealerApplication;
use App\Models\User;
use App\Services\Dealers\DealerApplicationAccessService;

class DealerApplicationPolicy
{
    public function __construct(
        private readonly DealerApplicationAccessService $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canViewAll($user)
            || $user->isManagerUser()
            || $user->employee_id !== null;
    }

    public function view(User $user, DealerApplication $dealerApplication): bool
    {
        return $this->access->canView($user, $dealerApplication);
    }

    public function create(User $user): bool
    {
        return $user->employee_id !== null && ! $user->isManagerUser() && ! $user->isAdminUser();
    }

    public function update(User $user, DealerApplication $dealerApplication): bool
    {
        return $this->access->canManageAsEmployee($user, $dealerApplication);
    }

    public function submit(User $user, DealerApplication $dealerApplication): bool
    {
        return $this->access->canManageAsEmployee($user, $dealerApplication);
    }

    public function uploadDocument(User $user, DealerApplication $dealerApplication): bool
    {
        return $this->access->canManageAsEmployee($user, $dealerApplication);
    }

    public function viewDocument(User $user, DealerApplication $dealerApplication): bool
    {
        return $this->access->canView($user, $dealerApplication);
    }

    public function approveAsManager(User $user, DealerApplication $dealerApplication): bool
    {
        return $user->isManagerUser()
            && $this->access->canView($user, $dealerApplication)
            && $dealerApplication->status === DealerApplication::STATUS_PENDING_MANAGER;
    }

    public function rejectAsManager(User $user, DealerApplication $dealerApplication): bool
    {
        return $user->isManagerUser()
            && $this->access->canView($user, $dealerApplication)
            && in_array($dealerApplication->status, [
                DealerApplication::STATUS_PENDING_MANAGER,
                DealerApplication::STATUS_CORRECTION_REQUIRED,
            ], true);
    }

    public function sendBackAsManager(User $user, DealerApplication $dealerApplication): bool
    {
        return $this->approveAsManager($user, $dealerApplication);
    }

    public function approveAsAdmin(User $user, DealerApplication $dealerApplication): bool
    {
        return $user->isAdminUser()
            && $dealerApplication->status === DealerApplication::STATUS_PENDING_ADMIN;
    }

    public function rejectAsAdmin(User $user, DealerApplication $dealerApplication): bool
    {
        return $user->isAdminUser()
            && in_array($dealerApplication->status, [
                DealerApplication::STATUS_PENDING_MANAGER,
                DealerApplication::STATUS_PENDING_ADMIN,
                DealerApplication::STATUS_CORRECTION_REQUIRED,
            ], true);
    }

    public function sendBackAsAdmin(User $user, DealerApplication $dealerApplication): bool
    {
        return $user->isAdminUser()
            && in_array($dealerApplication->status, [
                DealerApplication::STATUS_PENDING_MANAGER,
                DealerApplication::STATUS_PENDING_ADMIN,
            ], true);
    }
}
