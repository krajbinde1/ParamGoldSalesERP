<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Orders\ManagerOrderAccessService;
use App\Services\PaymentFollowUps\PaymentFollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manager payment follow-up monitoring (view-only) for direct reports.
 * Does not add, edit, or close employee follow-up entries.
 */
class ManagerPaymentFollowUpController extends Controller
{
    public function __construct(
        private readonly PaymentFollowUpService $followUps,
        private readonly ManagerOrderAccessService $teamAccess,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $reportIds = $this->teamAccess->directReportEmployeeIds($request->user());
        $employeeId = isset($validated['employee_id']) ? (int) $validated['employee_id'] : null;

        if ($employeeId !== null && ! in_array($employeeId, $reportIds, true)) {
            abort(403, 'You can only view payment follow-up of employees reporting to you.');
        }

        $dashboard = $this->followUps->directorMonitoringDashboard($employeeId, $reportIds);

        return response()->json([
            ...$dashboard,
            'employees' => $this->teamEmployees($reportIds),
        ]);
    }

    public function show(Request $request, int $dealer): JsonResponse
    {
        return response()->json(
            $this->followUps->showForManager($request->user(), $dealer),
        );
    }

    /**
     * @param  list<int>  $reportIds
     * @return list<array{employee_id: int, employee_name: string, employee_code: string|null}>
     */
    private function teamEmployees(array $reportIds): array
    {
        if ($reportIds === []) {
            return [];
        }

        return Employee::query()
            ->whereIn('id', $reportIds)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_code'])
            ->map(fn (Employee $employee): array => [
                'employee_id' => $employee->id,
                'employee_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
            ])
            ->values()
            ->all();
    }
}
