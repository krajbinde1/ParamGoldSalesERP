<?php

namespace App\Support\Orders;

use App\Models\Order;
use App\Services\Orders\OrderDispatchCalculationService;
use App\Services\Orders\OrderLineCalculationService;

final class OrderDetailPresenter
{
    public function __construct(
        private readonly OrderDispatchCalculationService $calculator,
        private readonly OrderLineCalculationService $lineCalculator = new OrderLineCalculationService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Order $order, bool $includeDispatchPreview = false, ?array $previewCalculation = null): array
    {
        $order->loadMissing([
            'dealer:id,dealer_code,firm_name,owner_name,village,taluka,district,state,mobile,address',
            'salesEmployee:id,full_name',
            'items.product:id,product_name,product_code,dealer_price',
            'approvedByUser:id,name',
            'rejectedByUser:id,name',
            'billedByUser:id,name',
            'dispatchedByUser:id,name',
        ]);

        $calculation = $previewCalculation
            ?? ($order->status === Order::STATUS_DISPATCHED
                ? $this->calculator->calculateForOrder($order)
                : $this->calculator->calculate($order, 'company_transport', 0));

        $storedItems = $order->items
            ->map(fn ($item): array => $this->lineCalculator->presentLineItem($item))
            ->values()
            ->all();

        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'order_date' => $order->order_date?->toDateString(),
            'created_at' => $order->created_at?->toDateTimeString(),
            'status' => $order->status,
            'status_label' => $order->displayStatusLabel(),
            'payment_type' => $order->payment_type,
            'remarks' => $order->remarks,
            'employee_name' => $order->salesEmployee?->full_name,
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
            'approved_at' => $order->approved_at?->toDateTimeString(),
            'approved_by_name' => $order->approvedByUser?->name,
            'approval_remark' => $order->remarks,
            'rejected_at' => $order->rejected_at?->toDateTimeString(),
            'rejected_by_name' => $order->rejectedByUser?->name,
            'rejected_by_role' => $order->rejected_by_role,
            'rejection_remark' => $order->rejection_remark,
            'rejection_reason' => $order->rejection_remark,
            'billed_at' => $order->billed_at?->toDateTimeString(),
            'billed_by_name' => $order->billedByUser?->name,
            'bill_number' => $order->bill_number,
            'bill_path' => $order->bill_path,
            'bill_url' => $order->billUrl(),
            'billing_remark' => $order->billing_remark,
            'dispatched_at' => $order->dispatched_at?->toDateTimeString(),
            'dispatch_date' => $order->dispatch_date?->toDateString(),
            'dispatched_by_name' => $order->dispatchedByUser?->name,
            'dispatch_remark' => $order->dispatch_remark,
            'transporter_name' => $order->transporter_name,
            'vehicle_number' => $order->vehicle_number,
            'lr_number' => $order->lr_number,
            'lr_document_path' => $order->lr_document_path,
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
            'items' => $calculation['items'] ?? $storedItems,
            'line_items' => $storedItems,
            'timeline' => $order->workflowTimeline(),
            'can_dispatch' => $order->canBeDispatched(),
            'can_bill' => $order->canBeBilled(),
            'can_approve' => $order->canBeApproved(),
            'can_reject' => $order->canBeRejected(),
        ];
    }
}
