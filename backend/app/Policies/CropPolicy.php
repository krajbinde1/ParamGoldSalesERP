<?php

namespace App\Policies;

use App\Models\Crop;
use App\Models\User;

class CropPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->usesAdminDirectorDashboard() || $user->isAdminUser();
    }

    public function view(User $user, Crop $crop): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isAdminUser() || $user->usesAdminDirectorDashboard();
    }

    public function update(User $user, Crop $crop): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Crop $crop): bool
    {
        return $this->create($user);
    }
}
