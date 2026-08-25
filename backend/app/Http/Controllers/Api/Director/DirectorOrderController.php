<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Orders\OrderDetailPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Company-wide Director order monitoring (view-only).
 * List payload aligned with Manager order monitoring for shared UI patterns.
 */
class DirectorOrderController extends Controller
{
    public function __construct(
        private readonly OrderDetailPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,active_non_dispatched,pending_approval,placed,approved,on_hold,reverted_to_manager,returned_to_manager,returned_by_production,pending_for_billing,billed,dispatched,rejected'],
            'sales_person' => ['nullable', 'string', 'max:100'],
            'dealer' => ['nullable', 'string', 'max:100'],
            'order_no' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $status = $validated['status'] ?? null;
        $perPage = (int) ($validated['per_page'] ?? 20);

        $orders = Order::query()
            ->with([
                'dealer:id,dealer_code,firm_name,village,taluka,district,state',
                'salesEmployee:id,full_name,employee_code',
                'rejectedByUser:id,name',
            ])
            ->when(filled($status), function ($q) use ($status): void {
                $this->applyStatusFilter($q, $status);
            })
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
            ->paginate($perPage);

        return response()->json([
            'data' => collect($orders->items())->map(fn (Order $order): array => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'order_date' => $order->order_date?->toDateString(),
                'created_at' => $order->created_at?->toDateTimeString(),
                'dealer_name' => $order->dealer?->firm_name,
                'dealer_code' => $order->dealer?->dealer_code,
                'dealer_village' => $order->dealer?->village,
                'dealer_taluka' => $order->dealer?->taluka,
                'dealer_district' => $order->dealer?->district,
                'dealer_location' => collect([
                    $order->dealer?->village,
                    $order->dealer?->taluka,
                    $order->dealer?->district,
                    $order->dealer?->state,
                ])->filter()->implode(', '),
                'employee_name' => $order->salesEmployee?->full_name,
                'employee_code' => $order->salesEmployee?->employee_code,
                'grand_total' => \App\Services\Orders\OrderBillingTransportCalculator::finalGrandTotal($order),
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
            'counts' => $this->companyCounts(),
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return response()->json([
            'data' => $this->presenter->present($order),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function companyCounts(): array
    {
        $base = Order::query();

        $pendingActive = (clone $base)->activeNonDispatched()->count();

        return [
            'pending' => $pendingActive,
            'active_non_dispatched' => $pendingActive,
            'pending_approval' => (clone $base)->where('status', Order::STATUS_PENDING_APPROVAL)->count(),
            'placed' => (clone $base)->where('status', Order::STATUS_PENDING_APPROVAL)->count(),
            'approved' => (clone $base)->where('status', Order::STATUS_APPROVED)->count(),
            'pending_for_billing' => (clone $base)->where('status', Order::STATUS_PENDING_FOR_BILLING)->count(),
            'sent_for_bill' => (clone $base)->where('status', Order::STATUS_PENDING_FOR_BILLING)->count(),
            'billed' => (clone $base)->where('status', Order::STATUS_BILLED)->count(),
            'rejected' => (clone $base)->where('status', Order::STATUS_REJECTED)->count(),
            'dispatched' => (clone $base)->where('status', Order::STATUS_DISPATCHED)->count(),
            'on_hold' => (clone $base)->where('status', Order::STATUS_ON_HOLD)->count(),
            'reverted_to_manager' => (clone $base)->where('status', Order::STATUS_REVERTED_TO_MANAGER)->count(),
            'returned_by_production' => (clone $base)->where('status', Order::STATUS_REVERTED_TO_MANAGER)->count(),
            'all' => (clone $base)->count(),
        ];
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        if (in_array($status, ['pending', 'active_non_dispatched'], true)) {
            $query->activeNonDispatched();

            return;
        }

        if (in_array($status, ['pending_approval', 'placed'], true)) {
            $query->where('status', Order::STATUS_PENDING_APPROVAL);

            return;
        }

        if ($status === 'approved') {
            $query->where('status', Order::STATUS_APPROVED);

            return;
        }

        if ($status === 'pending_for_billing') {
            $query->where('status', Order::STATUS_PENDING_FOR_BILLING);

            return;
        }

        if (in_array($status, ['reverted_to_manager', 'returned_to_manager', 'returned_by_production'], true)) {
            $query->where('status', Order::STATUS_REVERTED_TO_MANAGER);

            return;
        }

        if ($status === 'on_hold') {
            $query->where('status', Order::STATUS_ON_HOLD);

            return;
        }

        $query->where('status', $status);
    }
}
