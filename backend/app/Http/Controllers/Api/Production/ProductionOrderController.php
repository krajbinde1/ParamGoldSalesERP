<?php

namespace App\Http\Controllers\Api\Production;

use App\Actions\Orders\DispatchOrderWithTransport;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\OrderDispatchCalculationService;
use App\Support\Orders\OrderDetailPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionOrderController extends Controller
{
    public function __construct(
        private readonly OrderDetailPresenter $presenter,
        private readonly OrderDispatchCalculationService $calculator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $allowed = [
            Order::STATUS_APPROVED,
            Order::STATUS_BILLED,
            Order::STATUS_DISPATCHED,
        ];

        $orders = Order::query()
            ->whereIn('status', $allowed)
            ->with(['dealer:id,firm_name,village,address,mobile', 'salesEmployee:id,full_name'])
            ->when(
                $request->filled('status') && in_array($request->string('status')->toString(), $allowed, true),
                fn ($q) => $q->where('status', $request->string('status')),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($orders->items())->map(fn (Order $order): array => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'order_date' => $order->order_date?->toDateString(),
                'created_at' => $order->created_at?->toDateTimeString(),
                'approved_at' => $order->approved_at?->toDateTimeString(),
                'billed_at' => $order->billed_at?->toDateTimeString(),
                'dispatched_at' => $order->dispatched_at?->toDateTimeString(),
                'dealer_name' => $order->dealer?->firm_name,
                'dealer_village' => $order->dealer?->village,
                'delivery_address' => $order->dealer?->address,
                'employee_name' => $order->salesEmployee?->full_name,
                'grand_total' => (float) $order->grand_total,
                'status' => $order->status,
                'status_label' => $order->displayStatusLabel(),
                'can_dispatch' => $order->canBeDispatched(),
            ])->values(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
                'counts' => [
                    'approved' => Order::query()->where('status', Order::STATUS_APPROVED)->count(),
                    'billed' => Order::query()->where('status', Order::STATUS_BILLED)->count(),
                    'dispatched' => Order::query()->where('status', Order::STATUS_DISPATCHED)->count(),
                ],
            ],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return response()->json([
            'data' => $this->presenter->present($order),
        ]);
    }

    public function calculateDispatch(Request $request, Order $order): JsonResponse
    {
        $this->authorize('dispatch', $order);

        $validated = $request->validate([
            'transport_type' => ['required', 'in:company_transport,outside_transport'],
            'transport_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $calculation = $this->calculator->calculate(
            $order,
            $validated['transport_type'],
            (float) $validated['transport_amount'],
        );

        return response()->json([
            'data' => $this->presenter->present($order, previewCalculation: $calculation),
        ]);
    }

    public function dispatch(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'transport_type' => ['required', 'in:company_transport,outside_transport'],
            'transport_amount' => ['required', 'numeric', 'min:0'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'dispatch_date' => ['nullable', 'date'],
            'transporter_name' => ['nullable', 'string', 'max:255'],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'lr_number' => ['nullable', 'string', 'max:100'],
            'lr_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:10240'],
        ]);

        $result = app(DispatchOrderWithTransport::class)->execute(
            order: $order,
            actor: $request->user(),
            transportType: $validated['transport_type'],
            transportAmount: (float) $validated['transport_amount'],
            remark: $validated['remark'] ?? null,
            dispatchDate: $validated['dispatch_date'] ?? null,
            transporterName: $validated['transporter_name'] ?? null,
            vehicleNumber: $validated['vehicle_number'] ?? null,
            lrNumber: $validated['lr_number'] ?? null,
            lrDocument: $request->file('lr_document'),
        );

        return response()->json([
            'message' => 'Order dispatched successfully.',
            'data' => $this->presenter->present($result['order']),
        ]);
    }
}
