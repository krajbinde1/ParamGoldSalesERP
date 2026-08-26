<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CreditNote;
use App\Models\User;
use App\Services\CreditNotes\ManagerCreditNoteAccessService;
use Filament\Facades\Filament;

class CreditNotePolicy
{
    public function viewAny(User $user): bool
    {
        if ($this->filamentAdminUser($user)) {
            return true;
        }

        return $user->hasAnyRole([
            UserRole::Employee,
            UserRole::Manager,
        ]);
    }

    public function view(User $user, CreditNote $creditNote): bool
    {
        if ($this->filamentAdminUser($user)) {
            return true;
        }

        return match ($user->roleEnum()) {
            UserRole::Employee => $creditNote->sales_employee_id === $user->employee_id,
            UserRole::Manager => $this->managerOwns($user, $creditNote),
            default => false,
        };
    }

    public function create(User $user): bool
    {
        if (Filament::auth()->check()) {
            return false;
        }

        return $user->hasRole(UserRole::Employee);
    }

    public function update(User $user, CreditNote $creditNote): bool
    {
        if (Filament::auth()->check()) {
            return false;
        }

        if (! $creditNote->canBeEdited()) {
            return false;
        }

        if ($user->hasRole(UserRole::Manager)) {
            return $this->managerOwns($user, $creditNote);
        }

        return $user->hasRole(UserRole::Employee)
            && $creditNote->sales_employee_id === $user->employee_id;
    }

    public function approve(User $user, CreditNote $creditNote): bool
    {
        if (Filament::auth()->check() || $user->isAdminUser() || $user->isDirectorUser()) {
            return false;
        }

        return $user->hasRole(UserRole::Manager)
            && $creditNote->canBeApproved()
            && $this->managerOwns($user, $creditNote);
    }

    public function reject(User $user, CreditNote $creditNote): bool
    {
        if ($user->isAdminUser()) {
            return $creditNote->canBeRejectedByAdmin();
        }

        if ($user->isDirectorUser() || Filament::auth()->check()) {
            return false;
        }

        return $user->hasRole(UserRole::Manager)
            && $creditNote->canBeRejectedByManager()
            && $this->managerOwns($user, $creditNote);
    }

    public function complete(User $user, CreditNote $creditNote): bool
    {
        if (! $creditNote->canBeCompleted()) {
            return false;
        }

        return $user->isAdminUser();
    }

    public function delete(User $user, CreditNote $creditNote): bool
    {
        return false;
    }

    private function managerOwns(User $user, CreditNote $creditNote): bool
    {
        return app(ManagerCreditNoteAccessService::class)->managerCanAccess($user, $creditNote);
    }

    private function filamentAdminUser(User $user): bool
    {
        if (! Filament::auth()->check()) {
            return false;
        }

        return $user->isAdminUser() || $user->isDirectorUser();
    }
}
