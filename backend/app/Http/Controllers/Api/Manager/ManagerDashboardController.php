<?php

namespace App\Http\Controllers\Api\Manager;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Order;
use App\Models\TaDaClaim;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Orders\ManagerOrderAccessService;
use App\Support\AttendanceCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardMetricsService $metrics,
        private readonly ManagerOrderAccessService $orderAccess,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:today,week,month,custom'],
            'start_date' => ['nullable', 'date', 'required_if:period,custom'],
            'end_date' => ['nullable', 'date', 'required_if:period,custom', 'after_or_equal:start_date'],
        ]);

        $range = $this->metrics->resolveDateRange(
            $validated['period'] ?? 'month',
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        $teamOrderScope = $this->orderAccess->scopeToManagerTeam(Order::query(), $request->user());
        $reportIds = $this->orderAccess->directReportEmployeeIds($request->user());
        $today = AttendanceCalendar::today()->toDateString();

        $placedOrders = (clone $teamOrderScope)
            ->where('status', Order::STATUS_PENDING_APPROVAL)
            ->count();

        $pendingTaDa = $reportIds === []
            ? 0
            : TaDaClaim::query()
                ->whereIn('employee_id', $reportIds)
                ->where('status', TaDaClaim::STATUS_PENDING)
                ->count();

        $presentToday = $reportIds === []
            ? 0
            : Attendance::query()
                ->whereIn('employee_id', $reportIds)
                ->whereDate('attendance_date', $today)
                ->where('attendance_status', 'Present')
                ->count();

        return response()->json([
            'success' => true,
            'period' => $range['label'],
            'targets' => $this->metrics->targetSummary(),
            'orders' => [
                'pending_orders' => $placedOrders,
                'placed_orders' => $placedOrders,
                'approved_orders' => (clone $teamOrderScope)
                    ->whereIn('status', [Order::STATUS_APPROVED, Order::STATUS_BILLED])
                    ->count(),
                'returned_by_production' => (clone $teamOrderScope)
                    ->where('status', Order::STATUS_REVERTED_TO_MANAGER)
                    ->count(),
                'on_hold_orders' => (clone $teamOrderScope)
                    ->where('status', Order::STATUS_ON_HOLD)
                    ->count(),
                'dispatched_orders' => (clone $teamOrderScope)
                    ->where('status', Order::STATUS_DISPATCHED)
                    ->count(),
            ],
            'ta_da' => [
                'pending_claims' => $pendingTaDa,
                'approved_claims' => $reportIds === []
                    ? 0
                    : TaDaClaim::query()
                        ->whereIn('employee_id', $reportIds)
                        ->where('status', TaDaClaim::STATUS_APPROVED)
                        ->count(),
            ],
            'operations' => [
                'present_today' => $presentToday,
                'team_size' => count($reportIds),
            ],
            'modules' => [
                'placed_orders' => $placedOrders,
                'team_present_today' => $presentToday,
                'pending_ta_approvals' => $pendingTaDa,
                'team_employees' => count($reportIds),
            ],
            'employee_performance' => $this->metrics->employeePerformance(
                $range['start'],
                $range['end'],
                role: UserRole::Employee->value,
                reportingManagerId: $request->user()->employee_id,
            ),
            'pending_order_approvals' => (clone $teamOrderScope)
                ->where('status', Order::STATUS_PENDING_APPROVAL)
                ->with(['dealer:id,firm_name', 'salesEmployee:id,full_name,employee_code'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (Order $order): array => [
                    'id' => $order->id,
                    'order_no' => $order->order_no,
                    'order_date' => $order->order_date?->toDateString(),
                    'created_at' => $order->created_at?->toDateTimeString(),
                    'dealer_name' => $order->dealer?->firm_name,
                    'employee_name' => $order->salesEmployee?->full_name,
                    'employee_code' => $order->salesEmployee?->employee_code,
                    'grand_total' => (float) $order->grand_total,
                    'status' => $order->status,
                    'status_label' => $order->displayStatusLabel(),
                ]),
            'pending_order_approval_count' => $placedOrders,
            'pending_ta_da_approvals' => $reportIds === []
                ? []
                : TaDaClaim::query()
                    ->where('status', TaDaClaim::STATUS_PENDING)
                    ->whereIn('employee_id', $reportIds)
                    ->with('employee:id,full_name')
                    ->orderByDesc('claim_date')
                    ->limit(10)
                    ->get()
                    ->map(fn (TaDaClaim $claim): array => [
                        'id' => $claim->id,
                        'claim_date' => $claim->claim_date->toDateString(),
                        'employee_name' => $claim->employee?->full_name,
                        'travel_km' => (float) $claim->travel_km,
                        'total_amount' => (float) $claim->total_amount,
                        'status' => $claim->status,
                    ]),
        ]);
    }
}
