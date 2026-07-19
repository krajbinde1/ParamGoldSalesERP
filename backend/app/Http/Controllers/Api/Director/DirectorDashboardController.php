<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DirectorDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardMetricsService $metrics,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:today,week,month,custom'],
            'start_date' => ['nullable', 'date', 'required_if:period,custom'],
            'end_date' => ['nullable', 'date', 'required_if:period,custom', 'after_or_equal:start_date'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'role' => ['nullable', 'in:employee,manager,production_supervisor,director'],
        ]);

        $range = $this->metrics->resolveDateRange(
            $validated['period'] ?? 'month',
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        return response()->json([
            'success' => true,
            'period' => $range['label'],
            'company_summary' => [
                'targets' => $this->metrics->targetSummary(),
                'orders' => $this->metrics->orderSummary(null, $range['start'], $range['end']),
                'ta_da' => $this->metrics->taDaSummary(),
                'operations' => $this->metrics->operationalSummary($range['start'], $range['end']),
            ],
            'employee_performance' => $this->metrics->employeePerformance(
                $range['start'],
                $range['end'],
                $validated['employee_id'] ?? null,
                $validated['role'] ?? null,
            ),
        ]);
    }
}
