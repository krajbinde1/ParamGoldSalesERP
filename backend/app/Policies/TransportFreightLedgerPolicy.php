<?php

namespace App\Policies;

use App\Models\TransportFreightLedger;
use App\Models\User;

class TransportFreightLedgerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function view(User $user, TransportFreightLedger $transportFreightLedger): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TransportFreightLedger $transportFreightLedger): bool
    {
        return false;
    }

    public function delete(User $user, TransportFreightLedger $transportFreightLedger): bool
    {
        return false;
    }
}
