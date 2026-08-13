<?php

namespace App\Http\Controllers\Api\Production;

use App\Actions\Orders\DispatchOrderWithTransport;
use App\Actions\Orders\SendOrderForBilling;
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
            Order::STATUS_PENDING_FOR_BILLING,
            Order::STATUS_BILLED,
            Order::STATUS_DISPATCHED,
        ];

        // Map UI / legacy filter aliases → canonical DB statuses.
        $status = $request->string('status')->toString();
        if ($status === 'sent_for_bill') {
            $status = Order::STATUS_PENDING_FOR_BILLING;
        }
        if (in_array($status, [
            'approved_by_manager',
            'manager_approved',
            'approved_by_sales_manager',
        ], true)) {
            $status = Order::STATUS_APPROVED;
        }

        $orders = Order::query()
            ->whereIn('status', $allowed)
            ->with([
                'dealer:id,firm_name,village,address,mobile',
                'salesEmployee:id,full_name',
                'approvedByUser:id,name',
            ])
            ->when(
                filled($status) && in_array($status, $allowed, true),
                function ($q) use ($status): void {
                    $q->where('status', $status);
                    if ($status === Order::STATUS_APPROVED) {
                        $q->orderByDesc('approved_at');
                    }
                },
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
                'approved_by_name' => $order->approvedByUser?->name,
                'sent_for_bill_at' => $order->sent_for_bill_at?->toDateTimeString(),
                'billed_at' => $order->billed_at?->toDateTimeString(),
                'dispatched_at' => $order->dispatched_at?->toDateTimeString(),
                'dealer_name' => $order->dealer?->firm_name,
                'dealer_village' => $order->dealer?->village,
                'delivery_address' => $order->dealer?->address,
                'employee_name' => $order->salesEmployee?->full_name,
                'payment_type' => $order->payment_type,
                'grand_total' => (float) $order->grand_total,
                'vehicle_number' => $order->vehicle_number,
                'transport_amount' => $order->transport_amount !== null
                    ? (float) $order->transport_amount
                    : null,
                'bill_number' => $order->bill_number,
                'bill_date' => $order->bill_date?->toDateString(),
                'status' => $order->status,
                'status_label' => $order->displayStatusLabel(),
                'can_send_for_bill' => $order->canBeSentForBilling(),
                'can_dispatch' => $order->canBeDispatched(),
            ])->values(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
                'counts' => [
                    'approved' => Order::query()->where('status', Order::STATUS_APPROVED)->count(),
                    'sent_for_bill' => Order::query()->where('status', Order::STATUS_PENDING_FOR_BILLING)->count(),
                    'pending_for_billing' => Order::query()->where('status', Order::STATUS_PENDING_FOR_BILLING)->count(),
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

    public function sendForBill(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_number' => ['required', 'string', 'max:50'],
            'transport_freight' => ['required', 'numeric', 'min:0'],
            'transport_amount' => ['nullable', 'numeric', 'min:0'],
            'transport_remark' => ['nullable', 'string', 'max:2000'],
        ]);

        $freight = (float) ($validated['transport_freight'] ?? $validated['transport_amount'] ?? 0);

        $result = app(SendOrderForBilling::class)->execute(
            order: $order,
            actor: $request->user(),
            vehicleNumber: $validated['vehicle_number'],
            transportFreight: $freight,
            transportRemark: $validated['transport_remark'] ?? null,
        );

        return response()->json([
            'message' => 'Order sent for billing successfully.',
            'data' => $this->presenter->present($result['order']),
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
            lrDocument: $validated['lr_document'] ?? null,
        );

        return response()->json([
            'message' => 'Order dispatched successfully.',
            'data' => $this->presenter->present($result['order']),
        ]);
    }
}
