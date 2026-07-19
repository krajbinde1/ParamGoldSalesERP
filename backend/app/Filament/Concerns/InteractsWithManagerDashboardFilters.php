<?php

namespace App\Filament\Concerns;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Services\Dashboard\DashboardMetricsService;
use Illuminate\Support\Carbon;

trait InteractsWithManagerDashboardFilters
{
    protected function normalizeManagerPeriod(string $period): string
    {
        return match ($period) {
            'today' => 'today',
            'weekly', 'week' => 'week',
            'monthly', 'month' => 'month',
            'custom' => 'custom',
            default => 'month',
        };
    }

    protected function managerPeriodQueryValue(string $period): string
    {
        return match ($this->normalizeManagerPeriod($period)) {
            'today' => 'today',
            'week' => 'weekly',
            'month' => 'monthly',
            'custom' => 'custom',
            default => 'monthly',
        };
    }

    /**
     * @return array{start: Carbon, end: Carbon, label: string}
     */
    protected function resolveManagerDateRange(string $period, ?string $fromDate, ?string $toDate): array
    {
        $metrics = app(DashboardMetricsService::class);
        $normalized = $this->normalizeManagerPeriod($period);

        if ($normalized === 'custom' && (blank($fromDate) || blank($toDate))) {
            $normalized = 'month';
            $fromDate = null;
            $toDate = null;
        }

        return $metrics->resolveDateRange(
            $normalized === 'custom' ? 'custom' : $normalized,
            $fromDate,
            $toDate,
        );
    }

    protected function resolveManagerEmployeeId(?string $employeeId): ?int
    {
        if ($employeeId === null || $employeeId === '' || $employeeId === 'all') {
            return null;
        }

        if (! ctype_digit((string) $employeeId)) {
            return null;
        }

        $employee = Employee::query()
            ->with('user:id,employee_id,role')
            ->where('status', true)
            ->find((int) $employeeId);

        if ($employee === null || $employee->user === null || ! $employee->user->hasRole(UserRole::Employee)) {
            return null;
        }

        return $employee->id;
    }

    /**
     * @return list<array{id: int, name: string, code: ?string, search_label: string}>
     */
    protected function managerEmployeeOptions(?string $search = null): array
    {
        $query = Employee::query()
            ->where('status', true)
            ->whereHas(
                'user',
                fn ($userQuery) => $userQuery->where('role', UserRole::Employee->value),
            )
            ->orderBy('full_name');

        if (filled($search)) {
            $term = '%'.trim($search).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('full_name', 'like', $term)
                    ->orWhere('employee_code', 'like', $term)
                    ->orWhere('mobile', 'like', $term);
            });
        }

        return $query
            ->get(['id', 'full_name', 'employee_code'])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'code' => $employee->employee_code,
                'search_label' => strtolower(trim($employee->full_name.' '.$employee->employee_code)),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     summary: array<string, float>,
     *     range: array{start: Carbon, end: Carbon, label: string}
     * }
     */
    protected function fetchManagerTeamSummaryData(
        string $period,
        ?string $fromDate,
        ?string $toDate,
    ): array {
        $range = $this->resolveManagerDateRange($period, $fromDate, $toDate);
        $metrics = app(DashboardMetricsService::class);

        return [
            'summary' => $metrics->teamPerformanceSummary(
                $range['start'],
                $range['end'],
            ),
            'range' => $range,
        ];
    }

    /**
     * @return array{
     *     employees: list<array<string, mixed>>,
     *     summary: array<string, float>,
     *     range: array{start: Carbon, end: Carbon, label: string}
     * }
     */
    protected function fetchManagerPerformanceData(
        string $period,
        string $employeeId,
        ?string $fromDate,
        ?string $toDate,
    ): array {
        $range = $this->resolveManagerDateRange($period, $fromDate, $toDate);
        $resolvedEmployeeId = $this->resolveManagerEmployeeId($employeeId);
        $metrics = app(DashboardMetricsService::class);

        $employees = $metrics->employeePerformance(
            $range['start'],
            $range['end'],
            $resolvedEmployeeId,
            role: UserRole::Employee->value,
        );

        $summary = $metrics->teamPerformanceSummary(
            $range['start'],
            $range['end'],
            $resolvedEmployeeId,
        );

        return [
            'employees' => $employees,
            'summary' => $summary,
            'range' => $range,
        ];
    }
}
