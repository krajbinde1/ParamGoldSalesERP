<?php

namespace App\Policies;

use App\Models\StockLedger;
use App\Models\User;

class StockLedgerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function view(User $user, StockLedger $stockLedger): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockLedger $stockLedger): bool
    {
        return false;
    }

    public function delete(User $user, StockLedger $stockLedger): bool
    {
        return false;
    }
}
