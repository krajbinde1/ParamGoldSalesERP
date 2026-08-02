<?php

namespace App\Policies;

use App\Enums\RawMaterialInwardStatus;
use App\Models\PackagingMaterialInward;
use App\Models\User;

class PackagingMaterialInwardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function view(User $user, PackagingMaterialInward $packagingMaterialInward): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function create(User $user): bool
    {
        return $user->canCreateRawMaterialInward();
    }

    public function update(User $user, PackagingMaterialInward $packagingMaterialInward): bool
    {
        // Create always posts immediately; posted records are read-only.
        return false;
    }

    public function delete(User $user, PackagingMaterialInward $packagingMaterialInward): bool
    {
        return false;
    }

    public function submit(User $user, PackagingMaterialInward $packagingMaterialInward): bool
    {
        return false;
    }

    public function approve(User $user, PackagingMaterialInward $packagingMaterialInward): bool
    {
        return false;
    }

    public function post(User $user, PackagingMaterialInward $packagingMaterialInward): bool
    {
        // Separate post action removed; create posts in one step.
        return false;
    }

    public function cancel(User $user, PackagingMaterialInward $packagingMaterialInward): bool
    {
        // Only legacy non-posted drafts can still be cancelled (no stock impact).
        return $user->canCancelRawMaterialInward()
            && ! $packagingMaterialInward->status->isImmutable()
            && $packagingMaterialInward->status !== RawMaterialInwardStatus::Posted;
    }
}
