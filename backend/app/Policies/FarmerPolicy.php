<?php

namespace App\Policies;

use App\Models\Farmer;
use App\Models\User;

class FarmerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->usesAdminDirectorDashboard() || $user->isAdminUser();
    }

    public function view(User $user, Farmer $farmer): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Farmer $farmer): bool
    {
        return false;
    }

    public function delete(User $user, Farmer $farmer): bool
    {
        return false;
    }
}
