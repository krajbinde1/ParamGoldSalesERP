<?php

namespace App\Http\Controllers\Api\Director;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Dashboard\DirectorDashboardDataService;
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
        private readonly DirectorDashboardDataService $monitoring,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', DashboardMetricsService::periodValidationRule()],
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

        $employeePerformance = $this->metrics->employeePerformance(
            $range['start'],
            $range['end'],
            $validated['employee_id'] ?? null,
            null,
            null,
            null,
            $salesRoles,
        );

        // Summary targets/achievement match the filtered sales-team rows for the selected period.
        $salesTarget = round(collect($employeePerformance)->sum(
            fn (array $row): float => (float) ($row['sales_target'] ?? 0),
        ), 2);
        $salesAchieved = round(collect($employeePerformance)->sum(
            fn (array $row): float => (float) ($row['sales_achieved'] ?? 0),
        ), 2);
        $collectionTarget = round(collect($employeePerformance)->sum(
            fn (array $row): float => (float) ($row['collection_target'] ?? 0),
        ), 2);
        $collectionAchieved = round(collect($employeePerformance)->sum(
            fn (array $row): float => (float) ($row['collection_achieved'] ?? 0),
        ), 2);

        return response()->json([
            'success' => true,
            'period' => $range['label'],
            'company_summary' => [
                'targets' => [
                    'sales_target' => $salesTarget,
                    'sales_achieved' => $salesAchieved,
                    'sales_remaining' => round(max($salesTarget - $salesAchieved, 0), 2),
                    'sales_percentage' => $salesTarget > 0
                        ? round(($salesAchieved / $salesTarget) * 100, 2)
                        : 0,
                    'collection_target' => $collectionTarget,
                    'collection_achieved' => $collectionAchieved,
                    'collection_remaining' => round(max($collectionTarget - $collectionAchieved, 0), 2),
                    'collection_percentage' => $collectionTarget > 0
                        ? round(($collectionAchieved / $collectionTarget) * 100, 2)
                        : 0,
                ],
                'orders' => $this->metrics->orderSummary(null, $range['start'], $range['end']),
                'ta_da' => $this->metrics->taDaSummary(),
                'operations' => $this->metrics->operationalSummary($range['start'], $range['end']),
                'payment_requests' => [
                    'pending_approvals' => $pendingPaymentApprovals,
                ],
            ],
            'employee_performance' => $employeePerformance,
            'monitoring' => $this->monitoring->snapshot($user),
        ]);
    }
}
