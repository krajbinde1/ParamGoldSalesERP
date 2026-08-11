<?php

namespace App\Http\Controllers\Api\Manager;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TaDaClaim;
use App\Services\Dashboard\DashboardMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerDashboardController extends Controller
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
        ]);

        $range = $this->metrics->resolveDateRange(
            $validated['period'] ?? 'month',
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        return response()->json([
            'success' => true,
            'period' => $range['label'],
            'targets' => $this->metrics->targetSummary(),
            'orders' => $this->metrics->orderSummary(null, $range['start'], $range['end']),
            'ta_da' => $this->metrics->taDaSummary(),
            'operations' => $this->metrics->operationalSummary($range['start'], $range['end']),
            'employee_performance' => $this->metrics->employeePerformance(
                $range['start'],
                $range['end'],
                role: UserRole::Employee->value,
            ),
            'pending_order_approvals' => Order::query()
                ->where('status', 'pending_approval')
                ->with(['dealer:id,firm_name', 'salesEmployee:id,full_name'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (Order $order): array => [
                    'id' => $order->id,
                    'order_no' => $order->order_no,
                    'order_date' => $order->order_date?->toDateString(),
                    'dealer_name' => $order->dealer?->firm_name,
                    'employee_name' => $order->salesEmployee?->full_name,
                    'grand_total' => (float) $order->grand_total,
                    'status' => $order->status,
                    'status_label' => $order->displayStatusLabel(),
                ]),
            'pending_ta_da_approvals' => TaDaClaim::query()
                ->where('status', TaDaClaim::STATUS_PENDING)
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
