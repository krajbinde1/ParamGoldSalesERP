<?php

namespace App\Policies;

use App\Enums\RawMaterialInwardStatus;
use App\Models\RawMaterialInward;
use App\Models\User;

class RawMaterialInwardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function view(User $user, RawMaterialInward $rawMaterialInward): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function create(User $user): bool
    {
        return $user->canCreateRawMaterialInward();
    }

    public function update(User $user, RawMaterialInward $rawMaterialInward): bool
    {
        if (! $user->canUpdateRawMaterialInward()) {
            return false;
        }

        // Legacy drafts: normal edit.
        if ($rawMaterialInward->isEditable()) {
            return true;
        }

        // Posted with no dependent outward/production: safe reverse-then-repost.
        return $rawMaterialInward->canSafelyEditPosted();
    }

    public function delete(User $user, RawMaterialInward $rawMaterialInward): bool
    {
        return false;
    }

    public function submit(User $user, RawMaterialInward $rawMaterialInward): bool
    {
        return false;
    }

    public function approve(User $user, RawMaterialInward $rawMaterialInward): bool
    {
        return false;
    }

    public function post(User $user, RawMaterialInward $rawMaterialInward): bool
    {
        // Separate post action removed; create posts in one step.
        return false;
    }

    public function cancel(User $user, RawMaterialInward $rawMaterialInward): bool
    {
        // Only legacy non-posted drafts can still be cancelled (no stock impact).
        return $user->canCancelRawMaterialInward()
            && ! $rawMaterialInward->status->isImmutable()
            && $rawMaterialInward->status !== RawMaterialInwardStatus::Posted;
    }

    public function returnInward(User $user, RawMaterialInward $rawMaterialInward): bool
    {
        return $user->canPostRawMaterialInward()
            && in_array($rawMaterialInward->status, [
                RawMaterialInwardStatus::Posted,
                RawMaterialInwardStatus::Returned,
            ], true);
    }
}
