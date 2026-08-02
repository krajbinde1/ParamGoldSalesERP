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
            ->where('status', 'approved')
            ->with(['dealer:id,firm_name,village', 'salesEmployee:id,full_name'])
            ->orderByDesc('order_date')
            ->limit(20)
            ->get()
            ->map(fn (Order $order): array => $this->formatOrder($order));

        $recentDispatched = Order::query()
            ->where('status', Order::STATUS_DISPATCHED)
            ->with(['dealer:id,firm_name,village', 'salesEmployee:id,full_name'])
            ->orderByDesc('dispatched_at')
            ->limit(10)
            ->get()
            ->map(fn (Order $order): array => $this->formatOrder($order));

        return response()->json([
            'success' => true,
            'summary' => [
                'approved_orders' => Order::query()->where('status', 'approved')->count(),
                'ready_for_dispatch' => Order::query()->where('status', 'approved')->count(),
                'dispatched_orders' => Order::query()->where('status', Order::STATUS_DISPATCHED)->count(),
            ],
            'approved_orders' => $approvedOrders,
            'recent_dispatched' => $recentDispatched,
            // Inventory/production summary embedded so the mobile dashboard can
            // show both order and manufacturing widgets from a single call.
            // Mobile may also call GET /production/inventory/dashboard directly
            // for the full inventory-only payload.
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
            'dispatched_at' => $order->dispatched_at?->toDateTimeString(),
            'dealer_name' => $order->dealer?->firm_name,
            'dealer_village' => $order->dealer?->village,
            'employee_name' => $order->salesEmployee?->full_name,
            'grand_total' => (float) $order->grand_total,
            'status' => $order->status,
            'status_label' => Order::statusLabels()[$order->status] ?? ucfirst($order->status),
        ];
    }
}
