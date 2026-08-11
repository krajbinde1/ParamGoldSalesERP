<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $request->validate([
            'status' => ['nullable', 'string', 'in:pending_approval,approved,billed,dispatched,rejected'],
        ]);

        $orders = Order::query()
            ->with(['dealer:id,firm_name', 'salesEmployee:id,full_name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($orders->items())->map(fn (Order $order): array => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'order_date' => $order->order_date?->toDateString(),
                'created_at' => $order->created_at?->toDateTimeString(),
                'dealer_name' => $order->dealer?->firm_name,
                'employee_name' => $order->salesEmployee?->full_name,
                'grand_total' => (float) $order->grand_total,
                'status' => $order->status,
                'status_label' => $order->displayStatusLabel(),
                'approved_at' => $order->approved_at?->toDateTimeString(),
                'dispatched_at' => $order->dispatched_at?->toDateTimeString(),
            ])->values(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
            'counts' => [
                'pending_approval' => Order::query()->where('status', 'pending_approval')->count(),
                'approved' => Order::query()->where('status', 'approved')->count(),
                'dispatched' => Order::query()->where('status', Order::STATUS_DISPATCHED)->count(),
            ],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return response()->json([
            'data' => app(\App\Support\Orders\OrderDetailPresenter::class)->present($order),
        ]);
    }

    public function approve(Request $request, Order $order): JsonResponse
    {
        $this->authorize('approve', $order);

        $validated = $request->validate([
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);

        $order->approve($request->user()->id, $validated['remark'] ?? null);

        return response()->json([
            'message' => 'Order approved successfully.',
            'data' => ['id' => $order->id, 'status' => $order->fresh()->status],
        ]);
    }

    public function reject(Request $request, Order $order): JsonResponse
    {
        $this->authorize('reject', $order);

        $validated = $request->validate([
            'remark' => ['required_without:rejection_reason', 'nullable', 'string', 'min:3', 'max:2000'],
            'rejection_reason' => ['required_without:remark', 'nullable', 'string', 'min:3', 'max:2000'],
        ]);

        $remark = $validated['remark'] ?? $validated['rejection_reason'];

        app(\App\Actions\Orders\RejectOrderWithRemarks::class)->execute(
            order: $order,
            actor: $request->user(),
            remark: (string) $remark,
            rejectedByRole: Order::REJECTED_BY_ROLE_SALES_MANAGER,
        );

        return response()->json([
            'message' => 'Order rejected successfully.',
            'data' => [
                'id' => $order->id,
                'status' => $order->fresh()->status,
                'status_label' => $order->fresh()->displayStatusLabel(),
            ],
        ]);
    }
}
