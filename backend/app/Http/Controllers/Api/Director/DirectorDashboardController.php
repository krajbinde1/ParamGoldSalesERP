<?php

namespace App\Http\Controllers\Api\Director;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\PaymentRequests\PaymentRequestApproverResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DirectorDashboardController extends Controller
{
    /** @var list<string> */
    private const SALES_TEAM_ROLES = [
        UserRole::Manager->value,
        UserRole::Employee->value,
    ];

    public function __construct(
        private readonly DashboardMetricsService $metrics,
        private readonly PaymentRequestApproverResolver $approvers,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:today,week,month,custom'],
            'start_date' => ['nullable', 'date', 'required_if:period,custom'],
            'end_date' => ['nullable', 'date', 'required_if:period,custom', 'after_or_equal:start_date'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'role' => ['nullable', 'in:employee,manager'],
        ]);

        $range = $this->metrics->resolveDateRange(
            $validated['period'] ?? 'month',
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        $user = $request->user();
        $pendingPaymentApprovals = 0;
        if ($this->approvers->isFirstApprover($user)) {
            $pendingPaymentApprovals = PaymentRequest::query()
                ->where('status', PaymentRequest::STATUS_PENDING_FIRST)
                ->count();
        } elseif ($this->approvers->isSecondApprover($user)) {
            $pendingPaymentApprovals = PaymentRequest::query()
                ->where('status', PaymentRequest::STATUS_PENDING_SECOND)
                ->count();
        }

        // Director sales/team monitoring: login role manager|employee only.
        $salesRoles = isset($validated['role'])
            ? [$validated['role']]
            : self::SALES_TEAM_ROLES;

        return response()->json([
            'success' => true,
            'period' => $range['label'],
            'company_summary' => [
                'targets' => $this->metrics->targetSummary(),
                'orders' => $this->metrics->orderSummary(null, $range['start'], $range['end']),
                'ta_da' => $this->metrics->taDaSummary(),
                'operations' => $this->metrics->operationalSummary($range['start'], $range['end']),
                'payment_requests' => [
                    'pending_approvals' => $pendingPaymentApprovals,
                ],
            ],
            'employee_performance' => $this->metrics->employeePerformance(
                $range['start'],
                $range['end'],
                $validated['employee_id'] ?? null,
                null,
                null,
                null,
                $salesRoles,
            ),
        ]);
    }
}
