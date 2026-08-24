<?php

namespace App\Policies;

use App\Models\OrderEditPermissionRequest;
use App\Models\User;

class OrderEditPermissionRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isDirectorUser() && ! $user->isAdminUser();
    }

    public function view(User $user, OrderEditPermissionRequest $request): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, OrderEditPermissionRequest $request): bool
    {
        return false;
    }

    public function delete(User $user, OrderEditPermissionRequest $request): bool
    {
        return false;
    }

    public function approve(User $user, OrderEditPermissionRequest $request): bool
    {
        return $this->viewAny($user) && $request->isPending();
    }

    public function reject(User $user, OrderEditPermissionRequest $request): bool
    {
        return $this->viewAny($user) && $request->isPending();
    }
}
