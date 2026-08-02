<?php

namespace App\Policies;

use App\Models\PackagingMaterial;
use App\Models\User;

class PackagingMaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function view(User $user, PackagingMaterial $packagingMaterial): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function create(User $user): bool
    {
        return $user->canManageInventoryMasters();
    }

    public function update(User $user, PackagingMaterial $packagingMaterial): bool
    {
        return $user->canManageInventoryMasters();
    }

    public function delete(User $user, PackagingMaterial $packagingMaterial): bool
    {
        return $user->canManageInventoryMasters();
    }
}
