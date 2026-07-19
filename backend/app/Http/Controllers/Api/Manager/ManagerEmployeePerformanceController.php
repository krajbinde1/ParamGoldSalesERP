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

        $range = $this->metrics->resolveDateRange(
            $validated['period'] ?? 'month',
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        return response()->json([
            'success' => true,
            'period' => $range['label'],
            'period_key' => $validated['period'] ?? 'month',
            'start_date' => $range['start']->toDateString(),
            'end_date' => $range['end']->toDateString(),
            'data' => $this->metrics->employeePerformance(
                $range['start'],
                $range['end'],
                role: UserRole::Employee->value,
                search: $validated['search'] ?? null,
            ),
        ]);
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        $this->ensureEmployeeRoleTarget($employee);

        $validated = $this->validatedPeriod($request);

        $range = $this->metrics->resolveDateRange(
            $validated['period'] ?? 'month',
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        $performance = $this->metrics->employeePerformanceRow(
            $employee->loadMissing(['user:id,employee_id,role', 'reportingManager:id,full_name']),
            $range['start'],
            $range['end'],
        );

        $orders = $this->metrics->employeeOrdersForPeriod(
            $employee->id,
            $range['start'],
            $range['end'],
        );

        return response()->json([
            'success' => true,
            'period' => $range['label'],
            'period_key' => $validated['period'] ?? 'month',
            'start_date' => $range['start']->toDateString(),
            'end_date' => $range['end']->toDateString(),
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
            'period' => ['nullable', 'in:today,week,month,custom'],
            'start_date' => ['nullable', 'date', 'required_if:period,custom'],
            'end_date' => ['nullable', 'date', 'required_if:period,custom', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
    }

    private function ensureEmployeeRoleTarget(Employee $employee): void
    {
        if (! $employee->status || $employee->trashed()) {
            abort(404, 'Employee not found.');
        }

        $user = $employee->user;

        if ($user === null || ! $user->hasRole(UserRole::Employee)) {
            abort(403, 'You can only view employee-role performance.');
        }
    }
}
