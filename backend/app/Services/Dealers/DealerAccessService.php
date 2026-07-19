<?php

namespace App\Services\Dealers;

use App\Models\Dealer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class DealerAccessService
{
    public function canViewAll(User $user): bool
    {
        return $user->usesAdminDirectorDashboard() || $user->isAdminUser();
    }

    /**
     * @param  Builder<Dealer>  $query
     * @return Builder<Dealer>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($this->canViewAll($user)) {
            return $query;
        }

        if ($user->isManagerUser()) {
            $managerEmployeeId = $user->employee_id;

            if ($managerEmployeeId === null) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('assignedEmployee', function (Builder $employeeQuery) use ($managerEmployeeId): void {
                $employeeQuery->where('reporting_manager_id', $managerEmployeeId);
            });
        }

        if ($user->employee_id !== null) {
            return $query->where('assigned_employee_id', $user->employee_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function canAccessDealer(User $user, Dealer $dealer): bool
    {
        if ($this->canViewAll($user)) {
            return true;
        }

        if ($user->isManagerUser()) {
            $assignedEmployee = $dealer->assignedEmployee;

            return $assignedEmployee !== null
                && $assignedEmployee->reporting_manager_id === $user->employee_id;
        }

        return $user->employee_id !== null
            && (int) $dealer->assigned_employee_id === (int) $user->employee_id;
    }

    public function employeeCanAccessDealer(int $employeeId, Dealer $dealer): bool
    {
        return (int) $dealer->assigned_employee_id === $employeeId;
    }

    public function resolveAccessibleActiveDealer(User $user, int $dealerId): ?Dealer
    {
        $dealer = Dealer::query()
            ->whereKey($dealerId)
            ->where('status', true)
            ->first();

        if ($dealer === null) {
            return null;
        }

        return $this->canAccessDealer($user, $dealer) ? $dealer : null;
    }
}
