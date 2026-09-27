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
            UserRole::ProductionSupervisor,
        ]) || $this->isProductionActor($user);
    }

    public function view(User $user, CreditNote $creditNote): bool
    {
        if ($this->filamentAdminUser($user)) {
            return true;
        }

        return match ($user->roleEnum()) {
            UserRole::Employee => $creditNote->sales_employee_id === $user->employee_id,
            UserRole::Manager => $this->managerOwns($user, $creditNote),
            UserRole::ProductionSupervisor => $creditNote->isMoveToFactory(),
            default => $this->isProductionActor($user) && $creditNote->isMoveToFactory(),
        };
    }

    public function create(User $user): bool
    {
        if (Filament::auth()->check()) {
            return false;
        }

        return $user->hasAnyRole([
            UserRole::Employee,
            UserRole::Manager,
        ]);
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

    public function approveAsProduction(User $user, CreditNote $creditNote): bool
    {
        if (! $creditNote->canBeApprovedByProduction()) {
            return false;
        }

        return $this->isProductionActor($user);
    }

    public function reject(User $user, CreditNote $creditNote): bool
    {
        if ($user->isAdminUser()) {
            return $creditNote->canBeRejectedByAdmin();
        }

        if ($this->isProductionActor($user) && $creditNote->canBeRejectedByProductionManager()) {
            return true;
        }

        if ($user->isDirectorUser() && ! $this->isProductionActor($user)) {
            return false;
        }

        if (Filament::auth()->check() && ! $this->isProductionActor($user)) {
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

    private function isProductionActor(User $user): bool
    {
        return $user->hasRole(UserRole::ProductionSupervisor)
            || $user->hasProductionManagerJobRole()
            || $user->hasProductionSupervisorJobRole();
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
