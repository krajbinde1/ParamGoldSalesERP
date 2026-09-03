<?php

namespace App\Http\Controllers\Api\Manager;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Dashboard\DashboardMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerEmployeePerformanceController extends Controller
{
    public function __construct(
        private readonly DashboardMetricsService $metrics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validatedPeriod($request);
        $context = $this->metrics->periodContext(
            $validated['period'] ?? null,
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        return response()->json([
            'success' => true,
            'period' => $context['label'],
            'period_key' => $context['period'],
            'start_date' => $context['start_date'],
            'end_date' => $context['end_date'],
            'data' => $this->metrics->employeePerformance(
                $context['start'],
                $context['end'],
                role: UserRole::Employee->value,
                search: $validated['search'] ?? null,
                reportingManagerId: $request->user()->employee_id,
                period: $context['period'],
            ),
        ]);
    }

    /**
     * Team-level sales/collection targets for the manager's reporting employees.
     * Reuses the same employeePerformance rows as Team Performance (no duplicate calc).
     */
    public function targets(Request $request): JsonResponse
    {
        $validated = $this->validatedPeriod($request);
        $context = $this->metrics->periodContext(
            $validated['period'] ?? null,
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        $employees = $this->metrics->employeePerformance(
            $context['start'],
            $context['end'],
            role: UserRole::Employee->value,
            reportingManagerId: $request->user()->employee_id,
            period: $context['period'],
        );

        return response()->json([
            'success' => true,
            'period' => $context['label'],
            'period_key' => $context['period'],
            'start_date' => $context['start_date'],
            'end_date' => $context['end_date'],
            'summary' => $this->metrics->aggregateTeamPerformance($employees),
            'data' => $employees,
        ]);
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        $this->ensureEmployeeRoleTarget($request, $employee);

        $validated = $this->validatedPeriod($request);
        $context = $this->metrics->periodContext(
            $validated['period'] ?? null,
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        $performance = $this->metrics->employeePerformanceRow(
            $employee->loadMissing(['user:id,employee_id,role', 'reportingManager:id,full_name']),
            $context['start'],
            $context['end'],
            $context['period'],
        );

        $orders = $this->metrics->employeeOrdersForPeriod(
            $employee->id,
            $context['start'],
            $context['end'],
        );

        return response()->json([
            'success' => true,
            'period' => $context['label'],
            'period_key' => $context['period'],
            'start_date' => $context['start_date'],
            'end_date' => $context['end_date'],
            'performance' => $performance,
            'orders' => $orders,
            'order_summary' => [
                'total_orders' => $performance['total_orders'],
                'pending_orders' => $performance['pending_orders'],
                'approved_orders' => $performance['approved_orders'],
                'dispatched_orders' => $performance['dispatched_orders'],
                'rejected_orders' => $performance['rejected_orders'],
                'total_order_amount' => $performance['total_order_amount'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPeriod(Request $request): array
    {
        return $request->validate([
            'period' => ['nullable', DashboardMetricsService::periodValidationRule()],
            'start_date' => ['nullable', 'date', 'required_if:period,custom'],
            'end_date' => ['nullable', 'date', 'required_if:period,custom', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
    }

    private function ensureEmployeeRoleTarget(Request $request, Employee $employee): void
    {
        if (! $employee->status || $employee->trashed()) {
            abort(404, 'Employee not found.');
        }

        $user = $employee->user;

        if ($user === null || ! $user->hasRole(UserRole::Employee)) {
            abort(403, 'You can only view employee-role performance.');
        }

        if ((int) $employee->reporting_manager_id !== (int) $request->user()->employee_id) {
            abort(403, 'You can only view employees reporting to you.');
        }
    }
}
