<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Inventory\InventoryDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionDashboardController extends Controller
{
    public function __construct(
        private readonly InventoryDashboardService $inventoryDashboardService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $approvedOrders = Order::query()
            ->where('status', Order::STATUS_APPROVED)
            ->with(['dealer:id,firm_name,village', 'salesEmployee:id,full_name'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (Order $order): array => $this->formatOrder($order));

        $billedOrders = Order::query()
            ->where('status', Order::STATUS_BILLED)
            ->with(['dealer:id,firm_name,village', 'salesEmployee:id,full_name'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (Order $order): array => $this->formatOrder($order));

        $recentDispatched = Order::query()
            ->where('status', Order::STATUS_DISPATCHED)
            ->with(['dealer:id,firm_name,village', 'salesEmployee:id,full_name'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (Order $order): array => $this->formatOrder($order));

        $approvedCount = Order::query()->where('status', Order::STATUS_APPROVED)->count();
        $billedCount = Order::query()->where('status', Order::STATUS_BILLED)->count();
        $dispatchedCount = Order::query()->where('status', Order::STATUS_DISPATCHED)->count();

        return response()->json([
            'success' => true,
            'summary' => [
                'approved_orders' => $approvedCount,
                'billed_orders' => $billedCount,
                'ready_for_dispatch' => $billedCount,
                'dispatched_orders' => $dispatchedCount,
            ],
            'approved_orders' => $approvedOrders,
            'billed_orders' => $billedOrders,
            'recent_dispatched' => $recentDispatched,
            'inventory' => InventoryDashboardApiController::buildPayload($this->inventoryDashboardService, $request->user()),
        ]);
    }

    private function formatOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'order_date' => $order->order_date?->toDateString(),
            'approved_at' => $order->approved_at?->toDateTimeString(),
            'billed_at' => $order->billed_at?->toDateTimeString(),
            'dispatched_at' => $order->dispatched_at?->toDateTimeString(),
            'dealer_name' => $order->dealer?->firm_name,
            'dealer_village' => $order->dealer?->village,
            'employee_name' => $order->salesEmployee?->full_name,
            'grand_total' => (float) $order->grand_total,
            'status' => $order->status,
            'status_label' => $order->displayStatusLabel(),
            'can_dispatch' => $order->canBeDispatched(),
        ];
    }
}
