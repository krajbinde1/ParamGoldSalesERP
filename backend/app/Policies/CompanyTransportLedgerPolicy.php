<?php

namespace App\Policies;

use App\Models\CompanyTransportLedgerEntry;
use App\Models\User;

class CompanyTransportLedgerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdminUser()
            || $user->isDirectorUser()
            || $user->canActAsProductionSupervisor();
    }

    public function view(User $user, CompanyTransportLedgerEntry $entry): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isAdminUser() || $user->canActAsProductionSupervisor();
    }

    public function update(User $user, CompanyTransportLedgerEntry $entry): bool
    {
        if (! $entry->isExpense()) {
            return false;
        }

        return $user->isAdminUser();
    }

    public function delete(User $user, CompanyTransportLedgerEntry $entry): bool
    {
        return false;
    }

    public function export(User $user): bool
    {
        return $user->isAdminUser() || $user->isDirectorUser();
    }
}
