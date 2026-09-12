<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Controller;
use App\Services\Dealers\DealerOutstandingService;
use App\Services\PaymentFollowUps\PaymentFollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Director payment recovery monitoring.
 * History remains read-only; follow-ups can be set from No Follow-up Set.
 */
class DirectorPaymentFollowUpController extends Controller
{
    public function __construct(
        private readonly PaymentFollowUpService $followUps,
        private readonly DealerOutstandingService $outstanding,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $employees = $this->outstanding->salesEmployeeList();
        $employeeId = isset($validated['employee_id']) ? (int) $validated['employee_id'] : null;
        $dashboard = $this->followUps->directorMonitoringDashboard($employeeId);

        return response()->json([
            'employees' => $employees,
            ...$dashboard,
        ]);
    }

    public function show(int $dealer): JsonResponse
    {
        return response()->json(
            $this->followUps->showForDirector($dealer),
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
}
