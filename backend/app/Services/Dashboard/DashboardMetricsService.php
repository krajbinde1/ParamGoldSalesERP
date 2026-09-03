<?php

namespace App\Services\Dashboard;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Collection;
use App\Models\DealerVisit;
use App\Models\Employee;
use App\Models\FieldActivity;
use App\Models\MonthlyTarget;
use App\Models\Order;
use App\Models\TaDaClaim;
use App\Models\WeeklyTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DashboardMetricsService
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    /** @var list<string> */
    public const PERIOD_KEYS = ['today', 'week', 'last_week', 'last_month', 'month', 'custom'];

    public static function periodValidationRule(): string
    {
        return 'in:'.implode(',', self::PERIOD_KEYS);
    }

    public function periodHeading(?string $period, string $suffix = 'Performance'): string
    {
        $label = match ($period) {
            'today' => 'Today',
            'week', 'weekly' => 'This Week',
            'last_week' => 'Last Week',
            'last_month' => 'Last Month',
            'custom' => 'Custom',
            default => 'This Month',
        };

        return $label.' '.$suffix;
    }

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
                // Through current day only — do not include future weekdays.
                'end' => $today->copy()->endOfDay(),
                'label' => 'This Week',
            ],
            'last_week' => [
                'start' => $today->copy()->subWeek()->startOfWeek(Carbon::MONDAY),
                'end' => $today->copy()->subWeek()->startOfWeek(Carbon::MONDAY)->endOfWeek(Carbon::SUNDAY),
                'label' => 'Last Week',
            ],
            'last_month' => [
                'start' => $today->copy()->subMonthNoOverflow()->startOfMonth(),
                'end' => $today->copy()->subMonthNoOverflow()->endOfMonth(),
                'label' => 'Last Month',
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
        $fieldActivityTarget = (int) $targets->sum('field_activity_target');
        $salesAchieved = round($targets->sum(
            fn (WeeklyTarget $target): float => $target->salesAchieved($target->employee_id)
        ), 2);
        $collectionAchieved = round($targets->sum(
            fn (WeeklyTarget $target): float => $target->collectionAchieved($target->employee_id)
        ), 2);
        $fieldActivityAchieved = (int) $targets->sum(
            fn (WeeklyTarget $target): int => $target->fieldActivityAchieved($target->employee_id)
        );

        return [
            'sales_target' => $salesTarget,
            'sales_achieved' => $salesAchieved,
            'sales_percentage' => $this->percentage($salesTarget, $salesAchieved),
            'collection_target' => $collectionTarget,
            'collection_achieved' => $collectionAchieved,
            'collection_percentage' => $this->percentage($collectionTarget, $collectionAchieved),
            'field_activity_target' => $fieldActivityTarget,
            'field_activity_achieved' => $fieldActivityAchieved,
            'field_activity_remaining' => max($fieldActivityTarget - $fieldActivityAchieved, 0),
            'field_activity_percentage' => $this->percentage((float) $fieldActivityTarget, (float) $fieldActivityAchieved),
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
        ?string $period = null,
    ): array {
        $assigned = $this->assignedTargetsForPeriod($employeeId, $start, $end, $period);
        $salesTarget = $assigned['sales_target'];
        $collectionTarget = $assigned['collection_target'];
        $fieldActivityTarget = $assigned['field_activity_target'];

        if ($employeeId !== null) {
            $salesAchieved = $this->salesAchievedForPeriod($employeeId, $start, $end);
            $collectionAchieved = $this->collectionAchievedForPeriod($employeeId, $start, $end);
            $fieldActivityAchieved = $this->fieldActivityAchievedForPeriod($employeeId, $start, $end);
        } else {
            $employeeIds = Employee::query()
                ->where('status', true)
                ->pluck('id');
            $salesAchieved = round($employeeIds->sum(
                fn (int $id): float => $this->salesAchievedForPeriod($id, $start, $end)
            ), 2);
            $collectionAchieved = round($employeeIds->sum(
                fn (int $id): float => $this->collectionAchievedForPeriod($id, $start, $end)
            ), 2);
            $fieldActivityAchieved = (int) $employeeIds->sum(
                fn (int $id): int => $this->fieldActivityAchievedForPeriod($id, $start, $end)
            );
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
            'field_activity_target' => $fieldActivityTarget,
            'field_activity_achieved' => $fieldActivityAchieved,
            'field_activity_remaining' => max($fieldActivityTarget - $fieldActivityAchieved, 0),
            'field_activity_percentage' => $this->percentage((float) $fieldActivityTarget, (float) $fieldActivityAchieved),
        ];
    }

    /**
     * Dispatched orders whose order date falls in the selected period.
     * Used for both Sales Achieved and the Sales detail list so the totals match.
     *
     * @return Builder<Order>
     */
    public function salesOrdersQueryForPeriod(int $employeeId, Carbon $start, Carbon $end): Builder
    {
        return Order::query()
            ->where('sales_employee_id', $employeeId)
            ->where('status', Order::STATUS_DISPATCHED)
            ->whereDate('order_date', '>=', $start->toDateString())
            ->whereDate('order_date', '<=', $end->toDateString());
    }

    public function salesAchievedForPeriod(int $employeeId, Carbon $start, Carbon $end): float
    {
        return round((float) $this->salesOrdersQueryForPeriod($employeeId, $start, $end)->sum('grand_total'), 2);
    }

    /**
     * Received collections whose collection date falls in the selected period.
     * Used for both Collection Achieved and the Collection detail list.
     *
     * @return Builder<Collection>
     */
    public function collectionsQueryForPeriod(int $employeeId, Carbon $start, Carbon $end): Builder
    {
        return Collection::query()
            ->where('sales_employee_id', $employeeId)
            ->whereDate('collection_date', '>=', $start->toDateString())
            ->whereDate('collection_date', '<=', $end->toDateString())
            ->where('status', Collection::STATUS_RECEIVED);
    }

    public function collectionAchievedForPeriod(int $employeeId, Carbon $start, Carbon $end): float
    {
        return round((float) $this->collectionsQueryForPeriod($employeeId, $start, $end)->sum('amount'), 2);
    }

    /**
     * Field activities whose activity date falls in the selected period.
     * Used for both Field Activity Achieved and the Field Activity detail list.
     *
     * @return Builder<FieldActivity>
     */
    public function fieldActivitiesQueryForPeriod(int $employeeId, Carbon $start, Carbon $end): Builder
    {
        return FieldActivity::query()
            ->where('employee_id', $employeeId)
            ->whereDate('activity_date', '>=', $start->toDateString())
            ->whereDate('activity_date', '<=', $end->toDateString());
    }

    public function fieldActivityAchievedForPeriod(int $employeeId, Carbon $start, Carbon $end): int
    {
        return (int) $this->fieldActivitiesQueryForPeriod($employeeId, $start, $end)->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function employeeOrdersForPeriod(int $employeeId, Carbon $start, Carbon $end): array
    {
        return $this->salesOrdersQueryForPeriod($employeeId, $start, $end)
            ->with(['dealer:id,firm_name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'short_order_no' => $order->shortOrderNo(),
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
     * @return list<array<string, mixed>>
     */
    public function employeeCollectionsForPeriod(int $employeeId, Carbon $start, Carbon $end): array
    {
        return $this->collectionsQueryForPeriod($employeeId, $start, $end)
            ->with(['dealer:id,firm_name'])
            ->orderByDesc('collection_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Collection $collection): array => [
                'id' => $collection->id,
                'collection_date' => $collection->collection_date?->toDateString(),
                'dealer_name' => $collection->dealer?->firm_name,
                'amount' => (float) $collection->amount,
                'status' => $collection->status,
                'status_label' => $collection->statusLabel(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function employeeFieldActivitiesForPeriod(int $employeeId, Carbon $start, Carbon $end): array
    {
        return $this->fieldActivitiesQueryForPeriod($employeeId, $start, $end)
            ->with(['crop:id,name'])
            ->orderByDesc('activity_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (FieldActivity $activity): array {
                $typeLabel = filled($activity->activity_type)
                    ? Str::headline(str_replace('_', ' ', (string) $activity->activity_type))
                    : null;

                $details = collect([
                    $typeLabel,
                    $activity->crop?->name,
                    $activity->remark,
                ])->filter(fn (mixed $value): bool => filled($value))->implode(' · ');

                return [
                    'id' => $activity->id,
                    'activity_date' => $activity->activity_date?->toDateString(),
                    'farmer_name' => $activity->farmer_name,
                    'village' => $activity->village,
                    'details' => $details !== '' ? $details : '—',
                    'status' => $activity->status,
                    'status_label' => FieldActivity::statusLabel($activity->status),
                ];
            })
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
        ?string $period = null,
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

        return $employees->map(function (Employee $employee) use ($start, $end, $period): array {
            return $this->employeePerformanceRow($employee, $start, $end, $period);
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function employeePerformanceRow(
        Employee $employee,
        ?Carbon $start = null,
        ?Carbon $end = null,
        ?string $period = null,
    ): array {
        $start ??= Carbon::now(self::BUSINESS_TIMEZONE)->startOfMonth();
        $end ??= Carbon::now(self::BUSINESS_TIMEZONE)->endOfMonth();

        $orderScope = Order::query()->where('sales_employee_id', $employee->id);
        $targets = $this->targetSummaryForPeriod($employee->id, $start, $end, $period);
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
            'field_activity_target' => $targets['field_activity_target'],
            'field_activity_achieved' => $targets['field_activity_achieved'],
            'field_activity_remaining' => $targets['field_activity_remaining'],
            'field_activity_percentage' => $targets['field_activity_percentage'],
            'overall_percentage' => $this->overallPercentageFromTargets($targets),
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
        ?string $period = null,
    ): array {
        $start ??= Carbon::now(self::BUSINESS_TIMEZONE)->startOfMonth();
        $end ??= Carbon::now(self::BUSINESS_TIMEZONE)->endOfMonth();

        $employees = $this->employeePerformance(
            $start,
            $end,
            $employeeId,
            role: UserRole::Employee->value,
            reportingManagerId: $reportingManagerId,
            period: $period,
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

        $fieldActivityTarget = (int) array_sum(array_map(
            fn (array $row): int => (int) $row['field_activity_target'],
            $employees,
        ));

        $fieldActivityAchieved = (int) array_sum(array_map(
            fn (array $row): int => (int) $row['field_activity_achieved'],
            $employees,
        ));

        return [
            'sales_target' => $salesTarget,
            'sales_achieved' => $salesAchieved,
            'sales_pending' => round(max($salesTarget - $salesAchieved, 0), 2),
            'sales_percentage' => $this->percentage($salesTarget, $salesAchieved),
            'collection_target' => $collectionTarget,
            'collection_achieved' => $collectionAchieved,
            'collection_pending' => round(max($collectionTarget - $collectionAchieved, 0), 2),
            'collection_percentage' => $this->percentage($collectionTarget, $collectionAchieved),
            'field_activity_target' => $fieldActivityTarget,
            'field_activity_achieved' => $fieldActivityAchieved,
            'field_activity_pending' => max($fieldActivityTarget - $fieldActivityAchieved, 0),
            'field_activity_remaining' => max($fieldActivityTarget - $fieldActivityAchieved, 0),
            'field_activity_percentage' => $this->percentage((float) $fieldActivityTarget, (float) $fieldActivityAchieved),
        ];
    }

    private function percentage(float $target, float $achieved): float
    {
        return $target > 0 ? round(($achieved / $target) * 100, 2) : 0.0;
    }

    public function overallPercentage(float $salesPercentage, float $collectionPercentage, float $fieldActivityPercentage): float
    {
        return round(($salesPercentage + $collectionPercentage + $fieldActivityPercentage) / 3, 2);
    }

    /**
     * Average only metrics that have an assigned target. Null when every target is 0.
     *
     * @param  array<string, mixed>  $targets
     */
    public function overallPercentageFromTargets(array $targets): ?float
    {
        $parts = [];

        if ((float) ($targets['sales_target'] ?? 0) > 0) {
            $parts[] = (float) $targets['sales_percentage'];
        }
        if ((float) ($targets['collection_target'] ?? 0) > 0) {
            $parts[] = (float) $targets['collection_percentage'];
        }
        if ((float) ($targets['field_activity_target'] ?? 0) > 0) {
            $parts[] = (float) $targets['field_activity_percentage'];
        }

        if ($parts === []) {
            return null;
        }

        return round(array_sum($parts) / count($parts), 2);
    }

    /**
     * Full assigned target amounts for the selected filter. Never prorated.
     *
     * @return array{sales_target: float, collection_target: float, field_activity_target: int}
     */
    private function assignedTargetsForPeriod(
        ?int $employeeId,
        Carbon $start,
        Carbon $end,
        ?string $period = null,
    ): array {
        $window = $this->targetMatchWindow($period, $start, $end);
        $windowStart = $window['start']->toDateString();
        $windowEnd = $window['end']->toDateString();

        $monthlyQuery = MonthlyTarget::query()
            ->where('status', 'active')
            ->whereDate('month_start_date', '>=', $windowStart)
            ->whereDate('month_start_date', '<=', $windowEnd);

        if ($employeeId !== null) {
            $monthlyQuery->where('employee_id', $employeeId);
        }

        $monthlies = $monthlyQuery->get()->filter(function (MonthlyTarget $monthly) use ($windowStart, $windowEnd): bool {
            $monthStart = $monthly->month_start_date?->toDateString();
            $monthEnd = $monthly->monthEndDate()->toDateString();

            return $monthStart !== null
                && $this->dateRangeContained($monthStart, $monthEnd, $windowStart, $windowEnd);
        });

        $weeklyQuery = WeeklyTarget::query()
            ->where('status', 'active')
            ->whereDate('week_start_date', '>=', $windowStart)
            ->whereDate('week_end_date', '<=', $windowEnd);

        if ($employeeId !== null) {
            $weeklyQuery->where('employee_id', $employeeId);
        }

        $includedMonthlyIds = $monthlies->pluck('id')->all();
        $weeklies = $weeklyQuery->get()->reject(function (WeeklyTarget $weekly) use ($includedMonthlyIds): bool {
            return $weekly->monthly_target_id !== null
                && in_array((int) $weekly->monthly_target_id, $includedMonthlyIds, true);
        });

        if ($window['prefer_monthly'] && $monthlies->isNotEmpty()) {
            return [
                'sales_target' => round((float) $monthlies->sum('sales_target'), 2),
                'collection_target' => round((float) $monthlies->sum('collection_target'), 2),
                'field_activity_target' => (int) $monthlies->sum('field_activity_target'),
            ];
        }

        return [
            'sales_target' => round((float) $weeklies->sum('sales_target') + (float) $monthlies->sum('sales_target'), 2),
            'collection_target' => round((float) $weeklies->sum('collection_target') + (float) $monthlies->sum('collection_target'), 2),
            'field_activity_target' => (int) $weeklies->sum('field_activity_target') + (int) $monthlies->sum('field_activity_target'),
        ];
    }

    /**
     * @return array{start: Carbon, end: Carbon, prefer_monthly: bool}
     */
    private function targetMatchWindow(?string $period, Carbon $start, Carbon $end): array
    {
        $normalized = match ($period) {
            'weekly', 'week' => 'week',
            'last_week' => 'last_week',
            'monthly', 'month' => 'month',
            'last_month' => 'last_month',
            'today' => 'today',
            'custom' => 'custom',
            default => null,
        };

        if (in_array($normalized, ['week', 'last_week'], true)) {
            $weekStart = $start->copy()->timezone(self::BUSINESS_TIMEZONE)->startOfWeek(Carbon::MONDAY)->startOfDay();

            return [
                'start' => $weekStart,
                'end' => $weekStart->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay(),
                'prefer_monthly' => false,
            ];
        }

        if (in_array($normalized, ['month', 'last_month'], true)) {
            $monthStart = $start->copy()->timezone(self::BUSINESS_TIMEZONE)->startOfMonth()->startOfDay();

            return [
                'start' => $monthStart,
                'end' => $monthStart->copy()->endOfMonth()->startOfDay(),
                'prefer_monthly' => true,
            ];
        }

        $windowStart = $start->copy()->timezone(self::BUSINESS_TIMEZONE)->startOfDay();
        $windowEnd = $end->copy()->timezone(self::BUSINESS_TIMEZONE)->startOfDay();
        $isFullMonth = $windowStart->isSameDay($windowStart->copy()->startOfMonth())
            && $windowEnd->isSameDay($windowStart->copy()->endOfMonth()->startOfDay());

        return [
            'start' => $windowStart,
            'end' => $windowEnd,
            'prefer_monthly' => $isFullMonth,
        ];
    }

    private function dateRangeContained(string $innerStart, string $innerEnd, string $outerStart, string $outerEnd): bool
    {
        return $innerStart >= $outerStart && $innerEnd <= $outerEnd;
    }
}
