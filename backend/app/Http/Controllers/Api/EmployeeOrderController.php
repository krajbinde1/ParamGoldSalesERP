<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Dealers\DealerAccessService;
use App\Services\Orders\OrderLineCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeOrderController extends Controller
{
    public function __construct(
        private readonly OrderLineCalculationService $lineCalculator = new OrderLineCalculationService,
    ) {}

    private const ALLOWED_GST = [0, 5, 12, 18, 28];

    private const PENDING_STATUSES = [
        'pending',
        'pending_approval',
        'approved',
        'processing',
    ];

    private const REJECTED_STATUSES = [
        'rejected',
        'cancelled',
    ];

    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        $orders = Order::query()->where('sales_employee_id', $employee->id);

        if ($request->filled('filter')) {
            $filter = $request->query('filter');

            return response()->json([
                'orders' => $this->applyFilter($orders, $filter)
                    ->with('dealer:id,firm_name')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn (Order $order): array => $this->formatOrderListItem($order))
                    ->values(),
            ]);
        }

        $summary = [
            'total_orders' => (clone $orders)->count(),
            'pending_orders' => (clone $orders)->whereIn('status', self::PENDING_STATUSES)->count(),
            'dispatched_orders' => (clone $orders)->where('status', 'dispatched')->count(),
            'rejected_orders' => (clone $orders)->whereIn('status', self::REJECTED_STATUSES)->count(),
        ];

        $recentOrders = (clone $orders)
            ->with('dealer:id,firm_name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (Order $order): array => $this->formatOrderListItem($order))
            ->values();

        return response()->json([
            'summary' => $summary,
            'recent_orders' => $recentOrders,
        ]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeEmployeeOrder($request, $order);

        $order->load([
            'dealer:id,dealer_code,firm_name,owner_name,village,taluka,district,state,mobile,address',
            'salesEmployee:id,full_name',
            'items.product:id,product_name,product_code,dealer_price',
            'approvedByUser:id,name',
            'rejectedByUser:id,name',
            'billedByUser:id,name',
            'dispatchedByUser:id,name',
        ]);

        return response()->json([
            'data' => $this->formatOrderDetail($order),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateOrderPayload($request);

        $employee = $request->user()->employee;

        $dealer = $this->resolveActiveDealer($validated['dealer_id'], $request);

        $order = DB::transaction(function () use ($validated, $employee, $dealer): Order {
            $calculatedItems = $this->calculateItems($validated['items']);
            $totals = $this->summarizeItems($calculatedItems);

            $order = Order::query()->create([
                'order_no' => $this->generateOrderNumber(),
                'order_date' => Order::businessToday(),
                'dealer_id' => $dealer->id,
                'sales_employee_id' => $employee->id,
                'remarks' => $validated['remarks'] ?? null,
                'status' => 'pending_approval',
                'payment_type' => 'Credit',
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $totals['discount_amount'],
                'gst_amount' => $totals['gst_amount'],
                'grand_total' => $totals['grand_total'],
            ]);

            $this->persistItems($order, $calculatedItems);

            return $order->fresh(['items']);
        });

        return response()->json([
            'message' => 'Order submitted successfully.',
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'status' => $order->status,
            'grand_total' => (float) $order->grand_total,
        ]);
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $this->authorizeEmployeeOrder($request, $order);

        if ($order->status !== 'pending_approval') {
            return response()->json([
                'message' => 'Only orders pending approval can be edited.',
            ], 403);
        }

        $validated = $this->validateOrderPayload($request);
        $dealer = $this->resolveActiveDealer($validated['dealer_id'], $request);

        $order = DB::transaction(function () use ($validated, $order, $dealer): Order {
            $calculatedItems = $this->calculateItems($validated['items']);
            $totals = $this->summarizeItems($calculatedItems);

            $order->items()->delete();

            $order->update([
                'dealer_id' => $dealer->id,
                'remarks' => $validated['remarks'] ?? null,
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $totals['discount_amount'],
                'gst_amount' => $totals['gst_amount'],
                'grand_total' => $totals['grand_total'],
            ]);

            $this->persistItems($order, $calculatedItems);

            return $order->fresh([
                'dealer:id,firm_name,owner_name,village,mobile',
                'salesEmployee:id,full_name',
                'items.product:id,product_name,product_code,dealer_price',
            ]);
        });

        return response()->json([
            'message' => 'Order updated successfully.',
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'status' => $order->status,
            'grand_total' => (float) $order->grand_total,
            'data' => $this->formatOrderDetail($order),
        ]);
    }

    private function authorizeEmployeeOrder(Request $request, Order $order): void
    {
        $employee = $request->user()->employee;

        if ($order->sales_employee_id !== $employee->id) {
            abort(403, 'You are not authorized to access this order.');
        }
    }

    private function validateOrderPayload(Request $request): array
    {
        return $request->validate([
            'dealer_id' => ['required', 'integer', 'exists:dealers,id'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.case_quantity' => ['required', 'integer', 'min:1'],
            'items.*.rate_per_no' => ['required', 'numeric', 'min:0'],
            'items.*.rate_type' => ['nullable', 'in:price_list,fixed_rate'],
            'items.*.quantity' => ['prohibited'],
            'items.*.rate' => ['prohibited'],
            'items.*.total_quantity_nos' => ['prohibited'],
            'items.*.discount_type' => ['required', 'in:percentage'],
            'items.*.discount_value' => ['required', 'numeric', 'min:0'],
            'items.*.gst_percentage' => ['required', 'numeric', 'in:0,5,12,18,28'],
        ]);
    }

    private function resolveActiveDealer(int $dealerId, Request $request): Dealer
    {
        $dealer = app(DealerAccessService::class)->resolveAccessibleActiveDealer($request->user(), $dealerId);

        if ($dealer === null) {
            throw ValidationException::withMessages([
                'dealer_id' => 'Selected dealer is not available.',
            ]);
        }

        return $dealer;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function calculateItems(array $items): array
    {
        $calculatedItems = [];

        foreach ($items as $index => $item) {
            $product = Product::query()
                ->whereKey($item['product_id'])
                ->where('status', true)
                ->first();

            if ($product === null) {
                throw ValidationException::withMessages([
                    "items.$index.product_id" => 'Selected product is not active.',
                ]);
            }

            $calculatedItems[] = $this->calculateItem($item, $product, $index);
        }

        return $calculatedItems;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal: float, discount_amount: float, gst_amount: float, grand_total: float, total_cases: int, total_quantity_nos: int}
     */
    private function summarizeItems(array $items): array
    {
        $subtotal = 0.0;
        $totalDiscount = 0.0;
        $totalGst = 0.0;
        $totalCases = 0;
        $totalQuantityNos = 0;

        foreach ($items as $item) {
            $subtotal += $item['base_amount'];
            $totalDiscount += $item['discount_amount'];
            $totalGst += $item['gst_amount'];
            $totalCases += $item['case_quantity'];
            $totalQuantityNos += $item['total_quantity_nos'];
        }

        return [
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($totalDiscount, 2),
            'gst_amount' => round($totalGst, 2),
            'grand_total' => round($subtotal - $totalDiscount + $totalGst, 2),
            'total_cases' => $totalCases,
            'total_quantity_nos' => $totalQuantityNos,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function persistItems(Order $order, array $items): void
    {
        foreach ($items as $item) {
            $order->items()->create([
                'product_id' => $item['product_id'],
                'case_quantity' => $item['case_quantity'],
                'nos_per_case' => $item['nos_per_case'],
                'total_quantity_nos' => $item['total_quantity_nos'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'rate_per_no' => $item['rate_per_no'],
                'rate_type' => $item['rate_type'] ?? 'price_list',
                'rate' => $item['rate'],
                'discount_percentage' => $item['discount_percentage'],
                'discount_amount' => $item['discount_amount'],
                'gst_percentage' => $item['gst_percentage'],
                'base_amount' => $item['base_amount'],
                'taxable_amount' => $item['taxable_amount'],
                'gst_amount' => $item['gst_amount'],
                'final_amount' => $item['final_amount'],
                'line_total' => $item['line_total'],
            ]);
        }
    }

    private function applyFilter($query, string $filter)
    {
        return match ($filter) {
            'all' => $query,
            'pending' => $query->whereIn('status', self::PENDING_STATUSES),
            'dispatched' => $query->where('status', 'dispatched'),
            'rejected' => $query->whereIn('status', self::REJECTED_STATUSES),
            default => throw ValidationException::withMessages([
                'filter' => 'Invalid order filter.',
            ]),
        };
    }

    private function formatOrderListItem(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'dealer_name' => $order->dealer?->firm_name,
            'order_date' => $order->order_date->toDateString(),
            'created_at' => $order->created_at?->toDateTimeString(),
            'grand_total' => (float) $order->grand_total,
            'status' => $order->status,
            'status_label' => $order->displayStatusLabel(),
        ];
    }

    private function formatOrderDetail(Order $order): array
    {
        $items = $order->items
            ->map(fn ($item): array => $this->lineCalculator->presentLineItem($item))
            ->values()
            ->all();

        $totalCases = array_sum(array_column($items, 'case_quantity'));
        $totalQuantityNos = array_sum(array_column($items, 'total_quantity_nos'));

        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'order_date' => $order->order_date->toDateString(),
            'created_at' => $order->created_at?->toDateTimeString(),
            'status' => $order->status,
            'status_label' => $order->displayStatusLabel(),
            'remarks' => $order->remarks,
            'dealer' => $order->dealer === null ? null : [
                'id' => $order->dealer->id,
                'dealer_code' => $order->dealer->dealer_code,
                'firm_name' => $order->dealer->firm_name,
                'owner_name' => $order->dealer->owner_name,
                'mobile' => $order->dealer->mobile,
                'address' => $order->dealer->address,
                'village' => $order->dealer->village,
                'taluka' => $order->dealer->taluka,
                'district' => $order->dealer->district,
                'state' => $order->dealer->state,
            ],
            'dealer_name' => $order->dealer?->firm_name,
            'sales_employee_name' => $order->salesEmployee?->full_name,
            'approved_at' => $order->approved_at?->toDateTimeString(),
            'approved_by_name' => $order->approvedByUser?->name,
            'rejected_at' => $order->rejected_at?->toDateTimeString(),
            'rejected_by_name' => $order->rejectedByUser?->name,
            'rejected_by_role' => $order->rejected_by_role,
            'rejection_remark' => $order->rejection_remark,
            'rejection_reason' => $order->rejection_remark,
            'billed_at' => $order->billed_at?->toDateTimeString(),
            'billed_by_name' => $order->billedByUser?->name,
            'bill_number' => $order->bill_number,
            'bill_url' => $order->billUrl(),
            'billing_remark' => $order->billing_remark,
            'dispatched_at' => $order->dispatched_at?->toDateTimeString(),
            'dispatched_by_name' => $order->dispatchedByUser?->name,
            'dispatch_remark' => $order->dispatch_remark,
            'subtotal' => (float) $order->subtotal,
            'discount_amount' => (float) $order->discount_amount,
            'gst_amount' => (float) $order->gst_amount,
            'grand_total' => \App\Services\Orders\OrderBillingTransportCalculator::finalGrandTotal($order),
            'total_cases' => $totalCases,
            'total_quantity_nos' => $totalQuantityNos,
            ...\App\Services\Orders\OrderBillingTransportCalculator::present($order),
            'transport_type' => $order->transport_type,
            'transport_type_label' => filled($order->transport_type)
                ? \App\Enums\TransportType::tryFrom((string) $order->transport_type)?->label()
                : null,
            'subtotal_before_transport' => $order->subtotal_before_transport !== null
                ? (float) $order->subtotal_before_transport
                : null,
            'taxable_amount_after_transport' => $order->taxable_amount_after_transport !== null
                ? (float) $order->taxable_amount_after_transport
                : null,
            'can_edit' => $order->status === 'pending_approval',
            'timeline' => $order->workflowTimeline(),
            'items' => $items,
        ];
    }

    private function calculateItem(array $item, Product $product, int $index): array
    {
        try {
            return $this->lineCalculator->calculateForProduct(
                product: $product,
                caseQuantity: (int) $item['case_quantity'],
                ratePerNo: (float) $item['rate_per_no'],
                requestedDiscountPercentage: (float) $item['discount_value'],
                requestedGstPercentage: (float) $item['gst_percentage'],
                rateType: (string) ($item['rate_type'] ?? 'price_list'),
            );
        } catch (ValidationException $exception) {
            $messages = $exception->errors();
            $firstKey = array_key_first($messages);
            $firstMessage = $messages[$firstKey][0] ?? 'Invalid row data.';

            throw ValidationException::withMessages([
                "items.$index.".($firstKey ?? 'case_quantity') => $firstMessage,
            ]);
        }
    }

    private function generateOrderNumber(): string
    {
        $prefix = 'PG-'.now()->format('Ymd').'-';

        $lastNumber = Order::withTrashed()
            ->where('order_no', 'like', $prefix.'%')
            ->orderByDesc('order_no')
            ->value('order_no');

        $next = $lastNumber === null
            ? 1
            : ((int) substr($lastNumber, -4)) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
