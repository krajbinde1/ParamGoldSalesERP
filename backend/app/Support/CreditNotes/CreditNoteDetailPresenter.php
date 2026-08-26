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
            'items.product:id,product_name,product_code,dealer_price,uom',
            'approvedByUser:id,name,role,job_role,employee_id',
            'approvedByUser.employee:id,full_name,designation',
            'rejectedByUser:id,name,role,job_role,employee_id',
            'rejectedByUser.employee:id,full_name,designation',
            'completedByUser:id,name,role,job_role,employee_id',
            'completedByUser.employee:id,full_name,designation',
            'lastEditedByUser:id,name',
        ]);

        return [
            'id' => $creditNote->id,
            'credit_note_no' => $creditNote->credit_note_no,
            'type' => $creditNote->type,
            'type_label' => $creditNote->typeLabel(),
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
                'quantity' => (float) $item->quantity,
                'rate' => $item->rate === null ? null : (float) $item->rate,
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
            'credit_note_date' => $creditNote->credit_note_date?->toDateString(),
            'created_at' => $creditNote->created_at?->toDateTimeString(),
            'dealer_name' => $creditNote->dealer?->firm_name,
            'dealer_code' => $creditNote->dealer?->dealer_code,
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
