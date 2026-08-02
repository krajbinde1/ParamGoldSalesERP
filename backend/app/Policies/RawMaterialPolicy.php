<?php

namespace App\Policies;

use App\Models\RawMaterial;
use App\Models\User;

class RawMaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function view(User $user, RawMaterial $rawMaterial): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function create(User $user): bool
    {
        return $user->canManageInventoryMasters();
    }

    public function update(User $user, RawMaterial $rawMaterial): bool
    {
        return $user->canManageInventoryMasters();
    }

    public function delete(User $user, RawMaterial $rawMaterial): bool
    {
        return $user->canManageInventoryMasters();
    }
}
