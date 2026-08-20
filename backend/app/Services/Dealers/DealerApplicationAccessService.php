<?php

namespace App\Services\Dealers;

use App\Models\DealerApplication;
use App\Models\User;
use App\Services\Orders\ManagerOrderAccessService;
use Illuminate\Database\Eloquent\Builder;

final class DealerApplicationAccessService
{
    public function __construct(
        private readonly ManagerOrderAccessService $managerAccess,
    ) {}

    public function canViewAll(User $user): bool
    {
        return $user->isAdminUser() || $user->usesAdminDirectorDashboard();
    }

    /**
     * @param  Builder<DealerApplication>  $query
     * @return Builder<DealerApplication>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($this->canViewAll($user)) {
            return $query;
        }

        if ($user->isManagerUser()) {
            $reportIds = $this->managerAccess->directReportEmployeeIds($user);

            return $query->whereIn('employee_id', $reportIds === [] ? [0] : $reportIds);
        }

        if ($user->employee_id === null) {
            return $query->whereRaw('1 = 0');
        }

        $employeeId = (int) $user->employee_id;

        return $query->where(function (Builder $inner) use ($employeeId): void {
            $inner->where('employee_id', $employeeId)
                ->orWhereHas('dealer', fn (Builder $dealerQuery) => $dealerQuery->where('assigned_employee_id', $employeeId));
        });
    }

    public function canView(User $user, DealerApplication $application): bool
    {
        if ($this->canViewAll($user)) {
            return true;
        }

        if ($user->isManagerUser()) {
            $reportIds = $this->managerAccess->directReportEmployeeIds($user);

            return in_array((int) $application->employee_id, $reportIds, true);
        }

        if ($user->employee_id === null) {
            return false;
        }

        if ((int) $application->employee_id === (int) $user->employee_id) {
            return true;
        }

        return $application->dealer_id !== null
            && (int) $application->dealer?->assigned_employee_id === (int) $user->employee_id;
    }

    public function canManageAsEmployee(User $user, DealerApplication $application): bool
    {
        return $user->employee_id !== null
            && (int) $application->employee_id === (int) $user->employee_id
            && $application->isEditableByEmployee();
    }
}
