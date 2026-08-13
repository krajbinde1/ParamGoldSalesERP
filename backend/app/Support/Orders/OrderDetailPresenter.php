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
            'dealer:id,dealer_code,firm_name,owner_name,village,taluka,district,state,mobile,address,gst_no,pincode',
            'salesEmployee:id,full_name,employee_code,designation',
            'items.product:id,product_name,product_code,dealer_price',
            'approvedByUser:id,name,role,job_role,employee_id',
            'approvedByUser.employee:id,full_name,designation',
            'rejectedByUser:id,name,role,job_role,employee_id',
            'rejectedByUser.employee:id,full_name,designation',
            'lastEditedByUser:id,name',
            'sentForBillByUser:id,name,role,job_role,employee_id',
            'sentForBillByUser.employee:id,full_name,designation',
            'billedByUser:id,name,role,job_role,employee_id',
            'billedByUser.employee:id,full_name,designation',
            'dispatchedByUser:id,name,role,job_role,employee_id',
            'dispatchedByUser.employee:id,full_name,designation',
        ]);

        $calculation = $previewCalculation
            ?? ($order->status === Order::STATUS_DISPATCHED
                ? $this->calculator->calculateForOrder($order)
                : $this->calculator->calculate($order, 'company_transport', 0));

        $storedItems = $order->items
            ->map(fn ($item): array => $this->lineCalculator->presentLineItem($item))
            ->values()
            ->all();

        $approvedAtLabel = $order->approved_at
            ? $order->approved_at->timezone('Asia/Kolkata')->format('d M Y • h:i A')
            : null;
        $sentForBillAtLabel = $order->sent_for_bill_at
            ? $order->sent_for_bill_at->timezone('Asia/Kolkata')->format('d M Y • h:i A')
            : null;
        $billedAtLabel = $order->billed_at
            ? $order->billed_at->timezone('Asia/Kolkata')->format('d M Y • h:i A')
            : null;

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
            'employee_code' => $order->salesEmployee?->employee_code,
            'employee_designation' => $order->salesEmployee?->designation,
            'dealer' => $order->dealer === null ? null : [
                'id' => $order->dealer->id,
                'dealer_code' => $order->dealer->dealer_code,
                'firm_name' => $order->dealer->firm_name,
                'owner_name' => $order->dealer->owner_name,
                'mobile' => $order->dealer->mobile,
                'gst_no' => $order->dealer->gst_no,
                'address' => $order->dealer->address,
                'village' => $order->dealer->village,
                'taluka' => $order->dealer->taluka,
                'district' => $order->dealer->district,
                'state' => $order->dealer->state,
                'pincode' => $order->dealer->pincode,
            ],
            'approved_at' => $order->approved_at?->toDateTimeString(),
            'approved_at_label' => $approvedAtLabel,
            'approved_by' => $order->approved_by,
            'approved_by_name' => $order->approvedByUser?->name,
            'approved_by_role' => $order->displayActorRole($order->approvedByUser) ?? 'Sales Manager',
            'approval_summary' => filled($order->approved_at)
                ? trim(implode("\n", array_filter([
                    'Approved by Sales Manager',
                    $order->approvedByUser?->name,
                    $approvedAtLabel,
                ])))
                : null,
            'approval_remark' => $order->remarks,
            'rejected_at' => $order->rejected_at?->toDateTimeString(),
            'rejected_by' => $order->rejected_by,
            'rejected_by_name' => $order->rejectedByUser?->name,
            'rejected_by_role' => $order->rejected_by_role,
            'rejection_remark' => $order->rejection_remark,
            'rejection_reason' => $order->rejection_remark,
            'last_edited_at' => $order->last_edited_at?->toDateTimeString(),
            'last_edited_by' => $order->last_edited_by,
            'last_edited_by_name' => $order->lastEditedByUser?->name,
            'last_edited_by_role' => $order->last_edited_by_role,
            'billed_at' => $order->billed_at?->toDateTimeString(),
            'billed_at_label' => $billedAtLabel,
            'billed_by_name' => $order->billedByUser?->name,
            'billed_by_role' => $order->displayActorRole($order->billedByUser),
            'bill_number' => $order->bill_number,
            'bill_date' => $order->bill_date?->toDateString(),
            'bill_path' => $order->bill_path,
            'bill_url' => $order->billUrl(),
            'billing_remark' => $order->billing_remark,
            'sent_for_bill_at' => $order->sent_for_bill_at?->toDateTimeString(),
            'sent_for_bill_at_label' => $sentForBillAtLabel,
            'sent_for_bill_by_name' => $order->sentForBillByUser?->name,
            'sent_for_bill_by_role' => $order->displayActorRole($order->sentForBillByUser),
            'transport_remark' => $order->transport_remark,
            'awaiting_send_for_bill' => $order->isAwaitingSendForBill(),
            'billing_blocked_reason' => $order->isAwaitingSendForBill()
                ? 'Waiting for Production Supervisor to Send for Bill.'
                : null,
            'dispatched_at' => $order->dispatched_at?->toDateTimeString(),
            'dispatch_date' => $order->dispatch_date?->toDateString(),
            'dispatched_by_name' => $order->dispatchedByUser?->name,
            'dispatched_by_role' => $order->displayActorRole($order->dispatchedByUser),
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
            'transport_freight' => $order->transport_amount !== null ? (float) $order->transport_amount : null,
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
            'can_send_for_bill' => $order->canBeSentForBilling(),
            'can_bill' => $order->canBeBilled(),
            'can_approve' => $order->canBeApproved(),
            'can_reject' => $order->canBeRejected(),
            'can_edit' => $order->canBeEdited(),
        ];
    }
}
