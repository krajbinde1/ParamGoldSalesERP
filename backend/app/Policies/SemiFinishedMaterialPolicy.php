<?php

namespace App\Policies;

use App\Models\SemiFinishedMaterial;
use App\Models\User;

class SemiFinishedMaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function view(User $user, SemiFinishedMaterial $semiFinishedMaterial): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function create(User $user): bool
    {
        return $user->canManageInventoryMasters();
    }

    public function update(User $user, SemiFinishedMaterial $semiFinishedMaterial): bool
    {
        return $user->canManageInventoryMasters();
    }

    public function delete(User $user, SemiFinishedMaterial $semiFinishedMaterial): bool
    {
        return $user->canManageInventoryMasters();
    }
}
