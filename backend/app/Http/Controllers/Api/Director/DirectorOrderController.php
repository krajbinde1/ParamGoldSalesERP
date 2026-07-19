<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DirectorOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $orders = Order::query()
            ->with(['dealer:id,firm_name', 'salesEmployee:id,full_name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($orders->items())->map(fn (Order $order): array => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'order_date' => $order->order_date?->toDateString(),
                'dealer_name' => $order->dealer?->firm_name,
                'employee_name' => $order->salesEmployee?->full_name,
                'grand_total' => (float) $order->grand_total,
                'status' => $order->status,
                'status_label' => Order::statusLabels()[$order->status] ?? ucfirst($order->status),
            ])->values(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        $order->load([
            'dealer:id,firm_name,owner_name,village,mobile,address',
            'salesEmployee:id,full_name',
            'items.product:id,product_name,product_code,dealer_price',
            'approvedByUser:id,name',
            'rejectedByUser:id,name',
            'dispatchedByUser:id,name',
        ]);

        return response()->json([
            'data' => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'order_date' => $order->order_date?->toDateString(),
                'status' => $order->status,
                'status_label' => Order::statusLabels()[$order->status] ?? ucfirst($order->status),
                'dealer' => $order->dealer,
                'employee_name' => $order->salesEmployee?->full_name,
                'remarks' => $order->remarks,
                'rejection_remark' => $order->rejection_remark,
                'dispatch_remark' => $order->dispatch_remark,
                'approved_at' => $order->approved_at?->toDateTimeString(),
                'approved_by' => $order->approvedByUser?->name,
                'rejected_at' => $order->rejected_at?->toDateTimeString(),
                'rejected_by' => $order->rejectedByUser?->name,
                'dispatched_at' => $order->dispatched_at?->toDateTimeString(),
                'dispatched_by' => $order->dispatchedByUser?->name,
                'subtotal' => (float) $order->subtotal,
                'discount_amount' => (float) $order->discount_amount,
                'gst_amount' => (float) $order->gst_amount,
                'grand_total' => (float) $order->grand_total,
                'items' => $order->items->map(fn ($item): array => [
                    'product_name' => $item->product?->product_name,
                    'quantity' => (float) $item->quantity,
                    'unit' => $item->unit,
                    'rate' => (float) $item->rate,
                    'line_total' => (float) $item->line_total,
                ]),
            ],
        ]);
    }
}
