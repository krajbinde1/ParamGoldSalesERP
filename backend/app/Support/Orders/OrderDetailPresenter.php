<?php

namespace App\Support\Orders;

use App\Models\Order;
use App\Services\Orders\OrderDispatchCalculationService;

final class OrderDetailPresenter
{
    public function __construct(
        private readonly OrderDispatchCalculationService $calculator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Order $order, bool $includeDispatchPreview = false, ?array $previewCalculation = null): array
    {
        $order->loadMissing([
            'dealer:id,firm_name,owner_name,village,mobile,address',
            'salesEmployee:id,full_name',
            'items.product:id,product_name,product_code,dealer_price',
            'approvedByUser:id,name',
            'dispatchedByUser:id,name',
        ]);

        $calculation = $previewCalculation
            ?? ($order->status === Order::STATUS_DISPATCHED
                ? $this->calculator->calculateForOrder($order)
                : $this->calculator->calculate($order, 'company_transport', 0));

        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'order_date' => $order->order_date?->toDateString(),
            'status' => $order->status,
            'status_label' => Order::statusLabels()[$order->status] ?? ucfirst($order->status),
            'payment_type' => $order->payment_type,
            'remarks' => $order->remarks,
            'employee_name' => $order->salesEmployee?->full_name,
            'dealer' => $order->dealer,
            'approved_at' => $order->approved_at?->toDateTimeString(),
            'approved_by_name' => $order->approvedByUser?->name,
            'approval_remark' => $order->remarks,
            'rejection_remark' => $order->rejection_remark,
            'dispatched_at' => $order->dispatched_at?->toDateTimeString(),
            'dispatched_by_name' => $order->dispatchedByUser?->name,
            'dispatch_remark' => $order->dispatch_remark,
            'gross_amount' => (float) $order->subtotal,
            'total_discount' => (float) $order->discount_amount,
            'subtotal' => (float) $order->subtotal,
            'discount_amount' => (float) $order->discount_amount,
            'gst_amount' => (float) $order->gst_amount,
            'grand_total' => (float) $order->grand_total,
            'transport_type' => $order->transport_type,
            'transport_type_label' => filled($order->transport_type)
                ? \App\Enums\TransportType::from($order->transport_type)->label()
                : null,
            'transport_amount' => $order->transport_amount !== null ? (float) $order->transport_amount : null,
            'subtotal_before_transport' => $order->subtotal_before_transport !== null
                ? (float) $order->subtotal_before_transport
                : $calculation['subtotal_before_transport'],
            'taxable_amount_after_transport' => $order->taxable_amount_after_transport !== null
                ? (float) $order->taxable_amount_after_transport
                : $calculation['taxable_amount_after_transport'],
            'total_gst' => $order->status === Order::STATUS_DISPATCHED
                ? (float) $order->gst_amount
                : $calculation['total_gst'],
            'total_cases' => $calculation['total_cases'] ?? 0,
            'total_quantity_nos' => $calculation['total_quantity_nos'] ?? 0,
            'calculation' => $calculation,
            'items' => $calculation['items'],
            'can_dispatch' => $order->canBeDispatched(),
        ];
    }
}
