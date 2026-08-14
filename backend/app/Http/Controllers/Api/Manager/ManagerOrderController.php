<?php

namespace App\Http\Controllers\Api\Manager;

use App\Actions\Orders\RejectOrderWithRemarks;
use App\Actions\Orders\UpdatePendingOrder;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Dealers\DealerAccessService;
use App\Services\Orders\ManagerOrderAccessService;
use App\Support\Orders\OrderDetailPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ManagerOrderController extends Controller
{
    public function __construct(
        private readonly ManagerOrderAccessService $access,
        private readonly DealerAccessService $dealerAccess,
        private readonly OrderDetailPresenter $presenter,
        private readonly UpdatePendingOrder $updatePendingOrder,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:pending_approval,placed,approved,billed,dispatched,rejected'],
            'sales_person' => ['nullable', 'string', 'max:100'],
            'sales_employee_id' => ['nullable', 'integer'],
            'dealer' => ['nullable', 'string', 'max:100'],
            'order_no' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $teamQuery = $this->access->scopeToManagerTeam(Order::query(), $request->user());
        $status = $validated['status'] ?? null;

        $orders = (clone $teamQuery)
            ->with([
                'dealer:id,dealer_code,firm_name,village,taluka,district,state',
                'salesEmployee:id,full_name,employee_code',
                'rejectedByUser:id,name',
            ])
            ->when(filled($status), function ($q) use ($status): void {
                if (in_array($status, ['pending_approval', 'placed'], true)) {
                    $q->where('status', Order::STATUS_PENDING_APPROVAL);

                    return;
                }

                if ($status === 'approved') {
                    $q->whereIn('status', [Order::STATUS_APPROVED, Order::STATUS_BILLED]);

                    return;
                }

                $q->where('status', $status);
            })
            ->when(
                filled($validated['sales_employee_id'] ?? null),
                fn ($q) => $q->where('sales_employee_id', (int) $validated['sales_employee_id']),
            )
            ->when(filled($validated['sales_person'] ?? null), function ($q) use ($validated): void {
                $term = '%'.$validated['sales_person'].'%';
                $q->whereHas('salesEmployee', function ($employeeQuery) use ($term): void {
                    $employeeQuery->where('full_name', 'like', $term)
                        ->orWhere('employee_code', 'like', $term);
                });
            })
            ->when(filled($validated['dealer'] ?? null), function ($q) use ($validated): void {
                $term = '%'.$validated['dealer'].'%';
                $q->whereHas('dealer', function ($dealerQuery) use ($term): void {
                    $dealerQuery->where('firm_name', 'like', $term)
                        ->orWhere('dealer_code', 'like', $term);
                });
            })
            ->when(
                filled($validated['order_no'] ?? null),
                fn ($q) => $q->where('order_no', 'like', '%'.$validated['order_no'].'%'),
            )
            ->when(
                filled($validated['date_from'] ?? null),
                fn ($q) => $q->whereDate('order_date', '>=', $validated['date_from']),
            )
            ->when(
                filled($validated['date_to'] ?? null),
                fn ($q) => $q->whereDate('order_date', '<=', $validated['date_to']),
            )
            ->when(filled($validated['search'] ?? null), function ($q) use ($validated): void {
                $term = '%'.$validated['search'].'%';
                $q->where(function ($inner) use ($term): void {
                    $inner->where('order_no', 'like', $term)
                        ->orWhereHas('salesEmployee', function ($employeeQuery) use ($term): void {
                            $employeeQuery->where('full_name', 'like', $term)
                                ->orWhere('employee_code', 'like', $term);
                        })
                        ->orWhereHas('dealer', function ($dealerQuery) use ($term): void {
                            $dealerQuery->where('firm_name', 'like', $term)
                                ->orWhere('dealer_code', 'like', $term);
                        });
                });
            })
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
                'dealer_code' => $order->dealer?->dealer_code,
                'dealer_location' => collect([
                    $order->dealer?->village,
                    $order->dealer?->taluka,
                    $order->dealer?->district,
                    $order->dealer?->state,
                ])->filter()->implode(', '),
                'employee_name' => $order->salesEmployee?->full_name,
                'employee_code' => $order->salesEmployee?->employee_code,
                'grand_total' => (float) $order->grand_total,
                'status' => $order->status,
                'status_label' => $order->displayStatusLabel(),
                'approved_at' => $order->approved_at?->toDateTimeString(),
                'rejected_at' => $order->rejected_at?->toDateTimeString(),
                'rejected_by_name' => $order->rejectedByUser?->name,
                'rejection_remark' => $order->rejection_remark,
                'billed_at' => $order->billed_at?->toDateTimeString(),
                'dispatched_at' => $order->dispatched_at?->toDateTimeString(),
                'bill_url' => $order->billUrl(),
            ])->values(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
            'counts' => $this->teamCounts($request),
        ]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return response()->json([
            'data' => $this->presenter->present($order),
        ]);
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        $validated = $request->validate([
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

        $dealer = $this->dealerAccess->resolveAccessibleActiveDealer(
            $request->user(),
            (int) $validated['dealer_id'],
        );

        if ($dealer === null) {
            // Keep the original dealer on the order when manager edits line items only.
            if ((int) $order->dealer_id === (int) $validated['dealer_id']) {
                $dealer = $order->dealer;
            }
        }

        if ($dealer === null) {
            throw ValidationException::withMessages([
                'dealer_id' => 'Selected dealer is not available.',
            ]);
        }

        $order = $this->updatePendingOrder->execute(
            order: $order,
            payload: $validated,
            dealer: $dealer,
            editor: $request->user(),
            editedByRole: Order::REJECTED_BY_ROLE_SALES_MANAGER,
        );

        return response()->json([
            'message' => 'Order updated successfully.',
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'status' => $order->status,
            'grand_total' => (float) $order->grand_total,
            'data' => $this->presenter->present($order),
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
            'data' => [
                'id' => $order->id,
                'status' => $order->fresh()->status,
                'status_label' => $order->fresh()->displayStatusLabel(),
                'approved_by' => $order->fresh()->approved_by,
                'approved_at' => $order->fresh()->approved_at?->toDateTimeString(),
            ],
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

        app(RejectOrderWithRemarks::class)->execute(
            order: $order,
            actor: $request->user(),
            remark: (string) $remark,
            rejectedByRole: Order::REJECTED_BY_ROLE_SALES_MANAGER,
        );

        $fresh = $order->fresh();

        return response()->json([
            'message' => 'Order rejected successfully.',
            'data' => [
                'id' => $fresh->id,
                'status' => $fresh->status,
                'status_label' => $fresh->displayStatusLabel(),
                'rejected_by' => $fresh->rejected_by,
                'rejected_at' => $fresh->rejected_at?->toDateTimeString(),
                'rejection_remark' => $fresh->rejection_remark,
            ],
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function teamCounts(Request $request): array
    {
        $base = $this->access->scopeToManagerTeam(Order::query(), $request->user());

        return [
            'pending_approval' => (clone $base)->where('status', Order::STATUS_PENDING_APPROVAL)->count(),
            'placed' => (clone $base)->where('status', Order::STATUS_PENDING_APPROVAL)->count(),
            'approved' => (clone $base)->whereIn('status', [Order::STATUS_APPROVED, Order::STATUS_BILLED])->count(),
            'billed' => (clone $base)->where('status', Order::STATUS_BILLED)->count(),
            'rejected' => (clone $base)->where('status', Order::STATUS_REJECTED)->count(),
            'dispatched' => (clone $base)->where('status', Order::STATUS_DISPATCHED)->count(),
            'all' => (clone $base)->count(),
        ];
    }
}
