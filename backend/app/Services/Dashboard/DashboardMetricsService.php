<?php

namespace App\Services\Dashboard;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Collection;
use App\Models\DealerVisit;
use App\Models\Employee;
use App\Models\FieldActivity;
use App\Models\Order;
use App\Models\TaDaClaim;
use App\Models\WeeklyTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class DashboardMetricsService
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    /**
     * @return array{start: Carbon, end: Carbon, label: string}
     */
    public function resolveDateRange(?string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        $today = Carbon::now(self::BUSINESS_TIMEZONE)->startOfDay();

        return match ($period) {
            'today' => [
                'start' => $today->copy(),
                'end' => $today->copy()->endOfDay(),
                'label' => 'Today',
            ],
            'week' => [
                'start' => $today->copy()->startOfWeek(Carbon::MONDAY),
                'end' => $today->copy()->endOfWeek(Carbon::SUNDAY),
                'label' => 'This Week',
            ],
            'month' => [
                'start' => $today->copy()->startOfMonth(),
                'end' => $today->copy()->endOfMonth(),
                'label' => 'This Month',
            ],
            'custom' => [
                'start' => Carbon::parse($startDate, self::BUSINESS_TIMEZONE)->startOfDay(),
                'end' => Carbon::parse($endDate, self::BUSINESS_TIMEZONE)->endOfDay(),
                'label' => 'Custom Range',
            ],
            default => [
                'start' => $today->copy()->startOfMonth(),
                'end' => $today->copy()->endOfMonth(),
                'label' => 'This Month',
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function orderSummary(?Builder $scope = null, ?Carbon $start = null, ?Carbon $end = null): array
    {
        $query = Order::query();
        if ($scope !== null) {
            $query = clone $scope;
        }

        if ($start !== null && $end !== null) {
            $query->whereBetween('order_date', [$start->toDateString(), $end->toDateString()]);
        }

        return [
            'pending_orders' => (clone $query)->where('status', 'pending_approval')->count(),
            'approved_orders' => (clone $query)->where('status', 'approved')->count(),
            'dispatched_orders' => (clone $query)->where('status', Order::STATUS_DISPATCHED)->count(),
            'rejected_orders' => (clone $query)->where('status', 'rejected')->count(),
            'total_orders' => (clone $query)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function taDaSummary(?Builder $scope = null): array
    {
        $query = TaDaClaim::query();
        if ($scope !== null) {
            $query = clone $scope;
        }

        return [
            'pending_claims' => (clone $query)->where('status', TaDaClaim::STATUS_PENDING)->count(),
            'approved_claims' => (clone $query)->where('status', TaDaClaim::STATUS_APPROVED)->count(),
            'paid_claims' => (clone $query)->where('status', TaDaClaim::STATUS_PAID)->count(),
            'rejected_claims' => (clone $query)->where('status', TaDaClaim::STATUS_REJECTED)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function targetSummary(?int $employeeId = null, ?Carbon $date = null): array
    {
        $date ??= Carbon::now(self::BUSINESS_TIMEZONE)->startOfDay();

        $targetQuery = WeeklyTarget::query()
            ->where('status', 'active')
            ->whereDate('week_start_date', '<=', $date)
            ->whereDate('week_end_date', '>=', $date);

        if ($employeeId !== null) {
            $targetQuery->where('employee_id', $employeeId);
        }

        $targets = $targetQuery->get();

        $salesTarget = (float) $targets->sum('sales_target');
        $collectionTarget = (float) $targets->sum('collection_target');
        $salesAchieved = round($targets->sum(
            fn (WeeklyTarget $target): float => $target->salesAchieved($target->employee_id)
        ), 2);
        $collectionAchieved = round($targets->sum(
            fn (WeeklyTarget $target): float => $target->collectionAchieved($target->employee_id)
        ), 2);

        return [
            'sales_target' => $salesTarget,
            'sales_achieved' => $salesAchieved,
            'sales_percentage' => $this->percentage($salesTarget, $salesAchieved),
            'collection_target' => $collectionTarget,
            'collection_achieved' => $collectionAchieved,
            'collection_percentage' => $this->percentage($collectionTarget, $collectionAchieved),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function operationalSummary(?Carbon $start = null, ?Carbon $end = null, ?int $employeeId = null): array
    {
        $attendanceQuery = Attendance::query();
        $visitQuery = DealerVisit::query();
        $activityQuery = FieldActivity::query();
        $collectionQuery = Collection::query();

        if ($employeeId !== null) {
            $attendanceQuery->where('employee_id', $employeeId);
            $visitQuery->where('employee_id', $employeeId);
            $activityQuery->where('employee_id', $employeeId);
            $collectionQuery->where('sales_employee_id', $employeeId);
        }

        if ($start !== null && $end !== null) {
            $attendanceQuery->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()]);
            $visitQuery->whereBetween('visit_date', [$start->toDateString(), $end->toDateString()]);
            $activityQuery->whereBetween('activity_date', [$start->toDateString(), $end->toDateString()]);
            $collectionQuery->whereBetween('collection_date', [$start->toDateString(), $end->toDateString()]);
        }

        return [
            'attendance_records' => (clone $attendanceQuery)->count(),
            'present_today' => $presentToday = Attendance::query()
                ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
                ->whereDate('attendance_date', Carbon::now(self::BUSINESS_TIMEZONE)->toDateString())
                ->where('attendance_status', 'Present')
                ->count(),
            'absent_today' => max(
                Employee::query()->where('status', true)->when($employeeId !== null, fn ($q) => $q->where('id', $employeeId))->count() - $presentToday,
                0,
            ),
            'dealer_visits' => (clone $visitQuery)->count(),
            'field_activities' => (clone $activityQuery)->count(),
            'collections' => (clone $collectionQuery)->count(),
            'collection_amount' => round((float) (clone $collectionQuery)->sum('amount'), 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function targetSummaryForPeriod(
        ?int $employeeId,
        Carbon $start,
        Carbon $end,
    ): array {
        $targetQuery = WeeklyTarget::query()
            ->where('status', 'active')
            ->whereDate('week_start_date', '<=', $end->toDateString())
            ->whereDate('week_end_date', '>=', $start->toDateString());

        if ($employeeId !== null) {
            $targetQuery->where('employee_id', $employeeId);
        }

        $targets = $targetQuery->get();

        $salesTarget = (float) $targets->sum('sales_target');
        $collectionTarget = (float) $targets->sum('collection_target');

        if ($employeeId !== null) {
            $salesAchieved = $this->salesAchievedForPeriod($employeeId, $start, $end);
            $collectionAchieved = $this->collectionAchievedForPeriod($employeeId, $start, $end);
        } else {
            $employeeIds = $targets->pluck('employee_id')->unique();
            $salesAchieved = round($employeeIds->sum(
                fn (int $id): float => $this->salesAchievedForPeriod($id, $start, $end)
            ), 2);
            $collectionAchieved = round($employeeIds->sum(
                fn (int $id): float => $this->collectionAchievedForPeriod($id, $start, $end)
            ), 2);
        }

        return [
            'sales_target' => $salesTarget,
            'sales_achieved' => round((float) $salesAchieved, 2),
            'sales_percentage' => $this->percentage($salesTarget, (float) $salesAchieved),
            'sales_remaining' => max($salesTarget - (float) $salesAchieved, 0),
            'collection_target' => $collectionTarget,
            'collection_achieved' => round((float) $collectionAchieved, 2),
            'collection_percentage' => $this->percentage($collectionTarget, (float) $collectionAchieved),
            'collection_remaining' => max($collectionTarget - (float) $collectionAchieved, 0),
        ];
    }

    public function salesAchievedForPeriod(int $employeeId, Carbon $start, Carbon $end): float
    {
        return (float) Order::query()
            ->where('sales_employee_id', $employeeId)
            ->where('status', Order::STATUS_DISPATCHED)
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhereRaw(
                        "DATE(CONVERT_TZ(updated_at, '+00:00', '+05:30')) BETWEEN ? AND ?",
                        [$start->toDateString(), $end->toDateString()]
                    );
            })
            ->sum('grand_total');
    }

    public function collectionAchievedForPeriod(int $employeeId, Carbon $start, Carbon $end): float
    {
        return (float) Collection::query()
            ->where('sales_employee_id', $employeeId)
            ->whereBetween('collection_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', Collection::STATUS_RECEIVED)
            ->sum('amount');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function employeeOrdersForPeriod(int $employeeId, Carbon $start, Carbon $end): array
    {
        return Order::query()
            ->with(['dealer:id,firm_name'])
            ->where('sales_employee_id', $employeeId)
            ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'order_date' => $order->order_date?->toDateString(),
                'dealer_name' => $order->dealer?->firm_name,
                'grand_total' => (float) $order->grand_total,
                'status' => $order->status,
                'status_label' => $order->displayStatusLabel(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>|null  $roles  Login roles to include (e.g. manager, employee).
     * @return list<array<string, mixed>>
     */
    public function employeePerformance(
        ?Carbon $start = null,
        ?Carbon $end = null,
        ?int $employeeId = null,
        ?string $role = null,
        ?string $search = null,
        ?int $reportingManagerId = null,
        ?array $roles = null,
    ): array {
        $employees = Employee::query()
            ->with([
                'user:id,employee_id,role',
                'reportingManager:id,full_name',
            ])
            ->when($employeeId !== null, fn ($q) => $q->where('id', $employeeId))
            ->when($reportingManagerId !== null, fn ($q) => $q->where('reporting_manager_id', $reportingManagerId))
            ->when($role !== null, fn ($q) => $q->whereHas(
                'user',
                fn ($userQuery) => $userQuery->where('role', $role),
            ))
            ->when($roles !== null && $roles !== [], fn ($q) => $q->whereHas(
                'user',
                fn ($userQuery) => $userQuery->whereIn('role', $roles),
            ))
            ->when(filled($search), function ($query) use ($search): void {
                $term = '%'.$search.'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('full_name', 'like', $term)
                        ->orWhere('employee_code', 'like', $term)
                        ->orWhere('mobile', 'like', $term);
                });
            })
            ->where('status', true)
            ->orderBy('full_name')
            ->get();

        return $employees->map(function (Employee $employee) use ($start, $end): array {
            return $this->employeePerformanceRow($employee, $start, $end);
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function employeePerformanceRow(
        Employee $employee,
        ?Carbon $start = null,
        ?Carbon $end = null,
    ): array {
        $start ??= Carbon::now(self::BUSINESS_TIMEZONE)->startOfMonth();
        $end ??= Carbon::now(self::BUSINESS_TIMEZONE)->endOfMonth();

        $orderScope = Order::query()->where('sales_employee_id', $employee->id);
        $targets = $this->targetSummaryForPeriod($employee->id, $start, $end);
        $orders = $this->orderSummary($orderScope, $start, $end);
        $totalOrderAmount = round((float) (clone $orderScope)
            ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
            ->sum('grand_total'), 2);

        $attendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->latest('attendance_date')
            ->first();

        $visits = DealerVisit::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('visit_date', [$start->toDateString(), $end->toDateString()])
            ->count();

        $activities = FieldActivity::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('activity_date', [$start->toDateString(), $end->toDateString()])
            ->count();

        return [
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'employee_name' => $employee->full_name,
            'mobile' => $employee->mobile,
            'base_location' => $employee->base_location,
            'reporting_manager' => $employee->reportingManager?->full_name,
            'role' => $employee->user?->role ?? UserRole::Employee->value,
            'role_label' => UserRole::tryFromMixed($employee->user?->role)->label(),
            'sales_target' => $targets['sales_target'],
            'sales_achieved' => $targets['sales_achieved'],
            'sales_percentage' => $targets['sales_percentage'],
            'sales_remaining' => $targets['sales_remaining'],
            'collection_target' => $targets['collection_target'],
            'collection_achieved' => $targets['collection_achieved'],
            'collection_percentage' => $targets['collection_percentage'],
            'collection_remaining' => $targets['collection_remaining'],
            'total_order_amount' => $totalOrderAmount,
            'total_orders' => $orders['total_orders'],
            'pending_orders' => $orders['pending_orders'],
            'approved_orders' => $orders['approved_orders'],
            'dispatched_orders' => $orders['dispatched_orders'],
            'rejected_orders' => $orders['rejected_orders'],
            'total_collections' => $targets['collection_achieved'],
            'attendance_status' => strtolower($attendance?->attendance_status ?? 'absent'),
            'dealer_visits' => $visits,
            'field_activities' => $activities,
            'route_km' => 0,
        ];
    }

    /**
     * @return array<string, float>
     */
    public function teamPerformanceSummary(
        ?Carbon $start = null,
        ?Carbon $end = null,
        ?int $employeeId = null,
        ?int $reportingManagerId = null,
    ): array {
        $start ??= Carbon::now(self::BUSINESS_TIMEZONE)->startOfMonth();
        $end ??= Carbon::now(self::BUSINESS_TIMEZONE)->endOfMonth();

        $employees = $this->employeePerformance(
            $start,
            $end,
            $employeeId,
            role: UserRole::Employee->value,
            reportingManagerId: $reportingManagerId,
        );

        return $this->aggregateTeamPerformance($employees);
    }

    /**
     * Aggregate employee performance rows into team target totals.
     * Reuses the same per-employee sales/collection values as Team Performance.
     *
     * @param  list<array<string, mixed>>  $employees
     * @return array<string, float>
     */
    public function aggregateTeamPerformance(array $employees): array
    {
        $salesTarget = round(array_sum(array_map(
            fn (array $row): float => (float) $row['sales_target'],
            $employees,
        )), 2);

        $salesAchieved = round(array_sum(array_map(
            fn (array $row): float => (float) $row['sales_achieved'],
            $employees,
        )), 2);

        $collectionTarget = round(array_sum(array_map(
            fn (array $row): float => (float) $row['collection_target'],
            $employees,
        )), 2);

        $collectionAchieved = round(array_sum(array_map(
            fn (array $row): float => (float) $row['collection_achieved'],
            $employees,
        )), 2);

        return [
            'sales_target' => $salesTarget,
            'sales_achieved' => $salesAchieved,
            'sales_pending' => round(max($salesTarget - $salesAchieved, 0), 2),
            'sales_percentage' => $this->percentage($salesTarget, $salesAchieved),
            'collection_target' => $collectionTarget,
            'collection_achieved' => $collectionAchieved,
            'collection_pending' => round(max($collectionTarget - $collectionAchieved, 0), 2),
            'collection_percentage' => $this->percentage($collectionTarget, $collectionAchieved),
        ];
    }

    private function percentage(float $target, float $achieved): float
    {
        return $target > 0 ? round(($achieved / $target) * 100, 2) : 0.0;
    }
}
