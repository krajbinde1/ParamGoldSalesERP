<?php

namespace App\Services\Orders;

use App\Models\Employee;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ManagerOrderAccessService
{
    /**
     * Employee IDs that report directly to the signed-in manager.
     *
     * @return list<int>
     */
    public function directReportEmployeeIds(User $manager): array
    {
        $managerEmployeeId = $manager->employee_id;

        if ($managerEmployeeId === null) {
            return [];
        }

        return Employee::query()
            ->where('reporting_manager_id', $managerEmployeeId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeToManagerTeam(Builder $query, User $manager): Builder
    {
        $employeeIds = $this->directReportEmployeeIds($manager);

        if ($employeeIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('sales_employee_id', $employeeIds);
    }

    public function managerCanAccessOrder(User $manager, Order $order): bool
    {
        if ($manager->employee_id === null) {
            return false;
        }

        $salesEmployee = $order->relationLoaded('salesEmployee')
            ? $order->salesEmployee
            : $order->salesEmployee()->first();

        if ($salesEmployee === null) {
            return false;
        }

        return (int) $salesEmployee->reporting_manager_id === (int) $manager->employee_id;
    }
}
