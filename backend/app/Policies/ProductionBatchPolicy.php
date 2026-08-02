<?php

namespace App\Policies;

use App\Models\ProductionBatch;
use App\Models\User;

class ProductionBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function view(User $user, ProductionBatch $productionBatch): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function create(User $user): bool
    {
        return $user->canPostProduction();
    }

    public function update(User $user, ProductionBatch $productionBatch): bool
    {
        if (! $productionBatch->isEditable()) {
            return false;
        }

        return $user->canPostProduction();
    }

    public function delete(User $user, ProductionBatch $productionBatch): bool
    {
        if (! $productionBatch->isEditable()) {
            return false;
        }

        return $user->usesAdminDirectorDashboard() || $user->isAdminUser();
    }

    public function reverse(User $user, ProductionBatch $productionBatch): bool
    {
        return $user->canReverseProductionBatch() && $productionBatch->isCompleted();
    }
}
