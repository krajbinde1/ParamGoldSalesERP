<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Controller;
use App\Services\Dealers\DealerOutstandingService;
use App\Services\PaymentFollowUps\PaymentFollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Director payment follow-up monitoring (view-only).
 * Does not add, edit, or close employee follow-up entries.
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
}
