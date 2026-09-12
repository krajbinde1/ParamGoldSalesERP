<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Orders\ManagerOrderAccessService;
use App\Services\PaymentFollowUps\PaymentFollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manager payment recovery monitoring for direct reports.
 * History remains read-only; follow-ups can be set from No Follow-up Set.
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

    public function store(Request $request, int $dealer): JsonResponse
    {
        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:2000'],
            'expected_amount' => ['nullable', 'numeric', 'gt:0'],
            'next_follow_up_date' => ['required', 'date'],
        ]);

        $detail = $this->followUps->addFollowUpForMonitor(
            $request->user(),
            $dealer,
            (string) $validated['remark'],
            isset($validated['expected_amount']) ? (float) $validated['expected_amount'] : null,
            (string) $validated['next_follow_up_date'],
        );

        return response()->json([
            'message' => 'Follow-up saved.',
            ...$detail,
        ], 201);
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
