<?php

namespace App\Support\CreditNotes;

use App\Models\CreditNote;

final class CreditNoteDetailPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(CreditNote $creditNote): array
    {
        $creditNote->loadMissing([
            'dealer:id,dealer_code,firm_name,owner_name,village,taluka,district,state,mobile,address',
            'salesEmployee:id,full_name,employee_code,designation',
            'items.product:id,product_name,product_code,dealer_price,uom,nos_per_case,gst_percentage',
            'destinationDealer:id,dealer_code,firm_name,owner_name,village,mobile',
            'linkedOrder:id,order_no,status,dealer_id,grand_total,source_credit_note_id,credit_note_link_role',
            'linkedOrder.dealer:id,firm_name,dealer_code',
            'linkedSourceOrder:id,order_no,status,dealer_id,grand_total,source_credit_note_id,credit_note_link_role',
            'linkedSourceOrder.dealer:id,firm_name,dealer_code',
            'approvedByUser:id,name,role,job_role,employee_id',
            'approvedByUser.employee:id,full_name,designation',
            'rejectedByUser:id,name,role,job_role,employee_id',
            'rejectedByUser.employee:id,full_name,designation',
            'completedByUser:id,name,role,job_role,employee_id',
            'completedByUser.employee:id,full_name,designation',
            'lastEditedByUser:id,name',
            'productionApprovedByUser:id,name,role,job_role,employee_id',
        ]);

        return [
            'id' => $creditNote->id,
            'credit_note_no' => $creditNote->credit_note_no,
            'type' => $creditNote->type,
            'type_label' => $creditNote->typeLabel(),
            'move_to' => $creditNote->move_to,
            'move_to_label' => $creditNote->moveToLabel(),
            'bill_reference' => $creditNote->bill_reference,
            'credit_note_date' => $creditNote->credit_note_date?->toDateString(),
            'created_at' => $creditNote->created_at?->toDateTimeString(),
            'amount' => (float) $creditNote->amount,
            'remarks' => $creditNote->remarks,
            'supporting_document_url' => $creditNote->documentUrl(),
            'supporting_document_is_image' => $creditNote->documentIsImage(),
            'status' => $creditNote->status,
            'status_label' => $creditNote->displayStatusLabel(),
            'can_edit' => $creditNote->canBeEdited(),
            'employee_name' => $creditNote->salesEmployee?->full_name,
            'employee_code' => $creditNote->salesEmployee?->employee_code,
            'employee_designation' => $creditNote->salesEmployee?->designation,
            'dealer' => $creditNote->dealer === null ? null : [
                'id' => $creditNote->dealer->id,
                'dealer_code' => $creditNote->dealer->dealer_code,
                'firm_name' => $creditNote->dealer->firm_name,
                'owner_name' => $creditNote->dealer->owner_name,
                'mobile' => $creditNote->dealer->mobile,
                'address' => $creditNote->dealer->address,
                'village' => $creditNote->dealer->village,
                'taluka' => $creditNote->dealer->taluka,
                'district' => $creditNote->dealer->district,
                'state' => $creditNote->dealer->state,
            ],
            'dealer_name' => $creditNote->dealer?->firm_name,
            'dealer_code' => $creditNote->dealer?->dealer_code,
            'destination_dealer' => $creditNote->destinationDealer === null ? null : [
                'id' => $creditNote->destinationDealer->id,
                'dealer_code' => $creditNote->destinationDealer->dealer_code,
                'firm_name' => $creditNote->destinationDealer->firm_name,
                'owner_name' => $creditNote->destinationDealer->owner_name,
                'mobile' => $creditNote->destinationDealer->mobile,
                'village' => $creditNote->destinationDealer->village,
            ],
            'destination_dealer_id' => $creditNote->destination_dealer_id,
            'destination_dealer_name' => $creditNote->destinationDealer?->firm_name,
            'linked_order' => $creditNote->linkedOrder === null ? null : [
                'id' => $creditNote->linkedOrder->id,
                'order_no' => $creditNote->linkedOrder->order_no,
                'status' => $creditNote->linkedOrder->status,
                'status_label' => $creditNote->linkedOrder->displayStatusLabel(),
                'dealer_id' => $creditNote->linkedOrder->dealer_id,
                'dealer_name' => $creditNote->linkedOrder->dealer?->firm_name,
                'grand_total' => (float) $creditNote->linkedOrder->grand_total,
                'role' => $creditNote->linkedOrder->credit_note_link_role,
            ],
            'linked_source_order' => $creditNote->linkedSourceOrder === null ? null : [
                'id' => $creditNote->linkedSourceOrder->id,
                'order_no' => $creditNote->linkedSourceOrder->order_no,
                'status' => $creditNote->linkedSourceOrder->status,
                'status_label' => $creditNote->linkedSourceOrder->displayStatusLabel(),
                'dealer_id' => $creditNote->linkedSourceOrder->dealer_id,
                'dealer_name' => $creditNote->linkedSourceOrder->dealer?->firm_name,
                'grand_total' => (float) $creditNote->linkedSourceOrder->grand_total,
                'role' => $creditNote->linkedSourceOrder->credit_note_link_role,
            ],
            'approved_at' => $creditNote->approved_at?->toDateTimeString(),
            'approved_by_name' => $creditNote->approvedByUser?->name,
            'approved_by_role' => $creditNote->displayActorRole($creditNote->approvedByUser) ?? 'Sales Manager',
            'approval_remark' => $creditNote->approval_remark,
            'rejected_at' => $creditNote->rejected_at?->toDateTimeString(),
            'rejected_by_name' => $creditNote->rejectedByUser?->name,
            'rejected_by_role' => $creditNote->rejected_by_role
                ?: $creditNote->displayActorRole($creditNote->rejectedByUser),
            'rejection_remark' => $creditNote->rejection_remark,
            'completed_at' => $creditNote->completed_at?->toDateTimeString(),
            'completed_by_name' => $creditNote->completedByUser?->name,
            'completed_by_role' => $creditNote->displayActorRole($creditNote->completedByUser) ?? 'Admin',
            'completion_remark' => $creditNote->completion_remark,
            'last_edited_at' => $creditNote->last_edited_at?->toDateTimeString(),
            'last_edited_by_name' => $creditNote->lastEditedByUser?->name,
            'last_edited_by_role' => $creditNote->last_edited_by_role,
            'items' => $creditNote->items->map(fn ($item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->product_name,
                'product_code' => $item->product?->product_code,
                'uom' => $item->product?->uom,
                'case_quantity' => $item->case_quantity === null ? null : (int) $item->case_quantity,
                'nos_per_case' => $item->nos_per_case === null ? null : (int) $item->nos_per_case,
                'total_quantity_nos' => $item->total_quantity_nos === null ? null : (int) $item->total_quantity_nos,
                'quantity' => (float) $item->quantity,
                'rate' => $item->rate === null ? null : (float) $item->rate,
                'rate_per_no' => $item->rate_per_no === null ? null : (float) $item->rate_per_no,
                'rate_type' => $item->rate_type,
                'original_dealer_price' => $item->product === null ? null : (float) $item->product->dealer_price,
                'discount_percentage' => $item->discount_percentage === null ? null : (float) $item->discount_percentage,
                'discount_amount' => $item->discount_amount === null ? null : (float) $item->discount_amount,
                'gst_percentage' => $item->gst_percentage === null ? null : (float) $item->gst_percentage,
                'base_amount' => $item->base_amount === null ? null : (float) $item->base_amount,
                'taxable_amount' => $item->taxable_amount === null ? null : (float) $item->taxable_amount,
                'gst_amount' => $item->gst_amount === null ? null : (float) $item->gst_amount,
                'final_amount' => $item->final_amount === null ? null : (float) $item->final_amount,
                'original_rate' => $item->original_rate === null ? null : (float) $item->original_rate,
                'revised_rate' => $item->revised_rate === null ? null : (float) $item->revised_rate,
                'amount' => (float) $item->amount,
                'reason' => $item->reason,
            ])->values()->all(),
            'timeline' => $creditNote->workflowTimeline(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentListItem(CreditNote $creditNote): array
    {
        return [
            'id' => $creditNote->id,
            'credit_note_no' => $creditNote->credit_note_no,
            'type' => $creditNote->type,
            'type_label' => $creditNote->typeLabel(),
            'move_to' => $creditNote->move_to,
            'move_to_label' => $creditNote->moveToLabel(),
            'credit_note_date' => $creditNote->credit_note_date?->toDateString(),
            'created_at' => $creditNote->created_at?->toDateTimeString(),
            'dealer_name' => $creditNote->dealer?->firm_name,
            'dealer_code' => $creditNote->dealer?->dealer_code,
            'destination_dealer_name' => $creditNote->destinationDealer?->firm_name,
            'linked_order_no' => $creditNote->linkedOrder?->order_no,
            'linked_source_order_no' => $creditNote->linkedSourceOrder?->order_no,
            'employee_name' => $creditNote->salesEmployee?->full_name,
            'employee_code' => $creditNote->salesEmployee?->employee_code,
            'bill_reference' => $creditNote->bill_reference,
            'amount' => (float) $creditNote->amount,
            'status' => $creditNote->status,
            'status_label' => $creditNote->displayStatusLabel(),
            'rejection_remark' => $creditNote->rejection_remark,
        ];
    }
}
