<?php

namespace App\Policies;

use App\Models\StockAdjustment;
use App\Models\User;

class StockAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAdjustStock();
    }

    public function view(User $user, StockAdjustment $stockAdjustment): bool
    {
        return $user->canAdjustStock();
    }

    public function create(User $user): bool
    {
        return $user->canAdjustStock();
    }

    public function update(User $user, StockAdjustment $stockAdjustment): bool
    {
        return false;
    }

    public function delete(User $user, StockAdjustment $stockAdjustment): bool
    {
        return false;
    }
}
