<?php

namespace App\Policies;

use App\Models\Bom;
use App\Models\User;

class BomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function view(User $user, Bom $bom): bool
    {
        if (! $user->canAccessInventoryModule()) {
            return false;
        }

        if ($user->canManageBom()) {
            return true;
        }

        return $bom->isActive();
    }

    public function create(User $user): bool
    {
        return $user->canManageBom();
    }

    public function update(User $user, Bom $bom): bool
    {
        return $user->canManageBom();
    }

    public function delete(User $user, Bom $bom): bool
    {
        return $user->canManageBom();
    }
}
