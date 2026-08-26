<?php

namespace App\Services\CreditNotes;

use App\Models\CreditNote;
use App\Models\User;
use App\Services\Orders\ManagerOrderAccessService;
use Illuminate\Database\Eloquent\Builder;

final class ManagerCreditNoteAccessService
{
    public function __construct(
        private readonly ManagerOrderAccessService $orders,
    ) {}

    /**
     * @return list<int>
     */
    public function directReportEmployeeIds(User $manager): array
    {
        return $this->orders->directReportEmployeeIds($manager);
    }

    /**
     * @param  Builder<CreditNote>  $query
     * @return Builder<CreditNote>
     */
    public function scopeToManagerTeam(Builder $query, User $manager): Builder
    {
        $employeeIds = $this->directReportEmployeeIds($manager);

        if ($employeeIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('sales_employee_id', $employeeIds);
    }

    public function managerCanAccess(User $manager, CreditNote $creditNote): bool
    {
        if ($manager->employee_id === null) {
            return false;
        }

        $salesEmployee = $creditNote->relationLoaded('salesEmployee')
            ? $creditNote->salesEmployee
            : $creditNote->salesEmployee()->first();

        if ($salesEmployee === null) {
            return false;
        }

        return (int) $salesEmployee->reporting_manager_id === (int) $manager->employee_id;
    }
}
