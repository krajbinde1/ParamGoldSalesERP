<?php

namespace App\Services\CreditNotes;

use App\Models\CreditNote;
use App\Models\Dealer;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderBillingTransportCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SalesReturnTransferOrderService
{
    public function syncLinkedOrder(CreditNote $creditNote): ?Order
    {
        if (! $creditNote->isMoveToDealer()) {
            $this->withdrawUnsyncedLinkedOrder($creditNote);

            return null;
        }

        $destinationDealer = $creditNote->destinationDealer ?? Dealer::query()->find($creditNote->destination_dealer_id);
        if ($destinationDealer === null) {
            throw ValidationException::withMessages([
                'destination_dealer_id' => ['Select the destination dealer for this return.'],
            ]);
        }

        if ((int) $destinationDealer->id === (int) $creditNote->dealer_id) {
            throw ValidationException::withMessages([
                'destination_dealer_id' => ['Destination dealer must be different from the returning dealer.'],
            ]);
        }

        return DB::transaction(function () use ($creditNote, $destinationDealer): ?Order {
            /** @var CreditNote $locked */
            $locked = CreditNote::query()->whereKey($creditNote->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('destinationDealer', $destinationDealer);
            $locked->loadMissing('items', 'dealer');

            $calculatedItems = $this->orderItemsFromCreditNote($locked);
            $totals = $this->summarize($calculatedItems);
            $remarks = $this->transferRemarks($locked);

            $existing = $this->existingLinkedOrder($locked);
            if ($existing !== null && ! in_array($existing->status, [
                Order::STATUS_PENDING_APPROVAL,
                Order::STATUS_REJECTED,
            ], true)) {
                return $existing;
            }

            $order = $existing ?? new Order([
                'order_no' => $this->generateOrderNumber(),
                'order_date' => Order::businessToday(),
            ]);

            if ($existing !== null) {
                $existing->items()->delete();
            }

            $order->fill([
                'dealer_id' => $destinationDealer->id,
                'sales_employee_id' => $locked->sales_employee_id,
                'source_credit_note_id' => $locked->id,
                'credit_note_link_role' => Order::CREDIT_NOTE_LINK_DESTINATION,
                'remarks' => $remarks,
                'status' => Order::STATUS_PENDING_APPROVAL,
                'payment_type' => 'Credit',
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $totals['discount_amount'],
                'gst_amount' => $totals['gst_amount'],
                'grand_total' => $totals['grand_total'],
                'unrounded_grand_total' => $totals['unrounded_grand_total'],
                'round_off' => $totals['round_off'],
                'rejected_by' => null,
                'rejected_by_role' => null,
                'rejected_at' => null,
                'rejection_remark' => null,
            ]);
            $order->save();
            $this->persistItems($order, $calculatedItems);
            $this->syncSourceRecord($locked, $order, $calculatedItems, $totals, $remarks);

            return $order->fresh(['items']);
        });
    }

    public function sendApprovedTransferToBilling(CreditNote $creditNote, ?int $userId): void
    {
        DB::transaction(function () use ($creditNote, $userId): void {
            $this->markSourceCreditProcessed($creditNote);

            $order = $this->existingLinkedOrder($creditNote);
            if ($order === null || $creditNote->isMoveToFactory()) {
                return;
            }

            if ($order->status === Order::STATUS_PENDING_FOR_BILLING
                || $order->status === Order::STATUS_BILLED
                || $order->status === Order::STATUS_DISPATCHED) {
                return;
            }

            if ($order->status === Order::STATUS_PENDING_APPROVAL) {
                $order->approve($userId);
                $order->refresh();
            }

            if ($order->status !== Order::STATUS_APPROVED) {
                return;
            }

            $now = Carbon::now('Asia/Kolkata');
            $order->update([
                'status' => Order::STATUS_PENDING_FOR_BILLING,
                'sent_for_bill_by' => $userId,
                'sent_for_bill_at' => $now,
            ]);
        });
    }

    public function rejectLinkedOrder(CreditNote $creditNote, User $actor, string $remark): void
    {
        $order = $this->existingLinkedOrder($creditNote);
        if ($order === null) {
            return;
        }

        if (in_array($order->status, [
            Order::STATUS_BILLED,
            Order::STATUS_DISPATCHED,
        ], true)) {
            return;
        }

        if ($order->status === Order::STATUS_REJECTED) {
            return;
        }

        $order->reject(
            $actor->id,
            $remark,
            Order::REJECTED_BY_ROLE_SALES_MANAGER,
        );
        $this->rejectSourceCreditOrder($creditNote, $actor, $remark);
    }

    private function withdrawUnsyncedLinkedOrder(CreditNote $creditNote): void
    {
        DB::transaction(function () use ($creditNote): void {
            $order = $this->existingLinkedOrder($creditNote);
            $source = $this->existingSourceOrder($creditNote);

            if ($order !== null && $order->status === Order::STATUS_PENDING_APPROVAL) {
                $order->update(['paired_order_id' => null]);
                if ($source !== null && $source->isCreditNoteSourceRecord()) {
                    $source->update(['paired_order_id' => null]);
                    $source->items()->delete();
                    $source->delete();
                }

                $order->items()->delete();
                $order->delete();
            }

            $creditNote->update([
                'linked_order_id' => null,
                'linked_source_order_id' => null,
            ]);
        });
    }

    private function existingLinkedOrder(CreditNote $creditNote): ?Order
    {
        if ($creditNote->linked_order_id) {
            $linked = Order::query()->find($creditNote->linked_order_id);
            if ($linked !== null && ! $linked->isCreditNoteSourceRecord()) {
                return $linked;
            }
        }

        return Order::query()
            ->where('source_credit_note_id', $creditNote->id)
            ->where(function ($query): void {
                $query->where('credit_note_link_role', Order::CREDIT_NOTE_LINK_DESTINATION)
                    ->orWhereNull('credit_note_link_role');
            })
            ->first();
    }

    private function existingSourceOrder(CreditNote $creditNote): ?Order
    {
        if ($creditNote->linked_source_order_id) {
            $linked = Order::query()->find($creditNote->linked_source_order_id);
            if ($linked !== null && $linked->isCreditNoteSourceRecord()) {
                return $linked;
            }
        }

        return Order::query()
            ->where('source_credit_note_id', $creditNote->id)
            ->where('credit_note_link_role', Order::CREDIT_NOTE_LINK_SOURCE)
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array{subtotal: float, discount_amount: float, gst_amount: float, grand_total: float, unrounded_grand_total: float, round_off: float}  $totals
     */
    private function syncSourceRecord(
        CreditNote $creditNote,
        Order $destination,
        array $items,
        array $totals,
        string $remarks,
    ): Order {
        $attributes = [
            'dealer_id' => $creditNote->dealer_id,
            'sales_employee_id' => $creditNote->sales_employee_id,
            'source_credit_note_id' => $creditNote->id,
            'credit_note_link_role' => Order::CREDIT_NOTE_LINK_SOURCE,
            'paired_order_id' => $destination->id,
            'remarks' => $remarks,
            'status' => Order::STATUS_CREDIT_PENDING,
            'payment_type' => 'Credit',
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'gst_amount' => $totals['gst_amount'],
            'grand_total' => $totals['grand_total'],
            'unrounded_grand_total' => $totals['unrounded_grand_total'],
            'round_off' => $totals['round_off'],
        ];

        $source = $this->existingSourceOrder($creditNote);
        if ($source === null) {
            $source = Order::query()->create([
                'order_no' => $this->generateOrderNumber(),
                'order_date' => $destination->order_date,
                ...$attributes,
            ]);
        } else {
            if (in_array($source->status, [
                Order::STATUS_CREDIT_PROCESSED,
                Order::STATUS_REJECTED,
            ], true)) {
                unset($attributes['status']);
            }

            $source->items()->delete();
            $source->update($attributes);
        }

        $this->persistItems($source, $items);
        $destination->update(['paired_order_id' => $source->id]);
        $creditNote->update([
            'linked_order_id' => $destination->id,
            'linked_source_order_id' => $source->id,
        ]);

        return $source;
    }

    private function markSourceCreditProcessed(CreditNote $creditNote): void
    {
        $source = $this->existingSourceOrder($creditNote);
        if ($source === null) {
            return;
        }

        if (in_array($source->status, [
            Order::STATUS_CREDIT_PENDING,
            Order::STATUS_CREDIT_NOTE_RECORD,
        ], true)) {
            $source->update(['status' => Order::STATUS_CREDIT_PROCESSED]);
            $source->refresh();
        }

        if ($source->status === Order::STATUS_CREDIT_PROCESSED) {
            app(\App\Services\Dealers\DealerLedgerPostingService::class)->syncSourceCreditOrder($source);
        }
    }

    private function rejectSourceCreditOrder(CreditNote $creditNote, User $actor, string $remark): void
    {
        $source = $this->existingSourceOrder($creditNote);
        if ($source === null || $source->status === Order::STATUS_REJECTED) {
            return;
        }

        if (! in_array($source->status, [
            Order::STATUS_CREDIT_PENDING,
            Order::STATUS_CREDIT_PROCESSED,
            Order::STATUS_CREDIT_NOTE_RECORD,
        ], true)) {
            return;
        }

        $source->update([
            'status' => Order::STATUS_REJECTED,
            'rejected_by' => $actor->id,
            'rejected_by_role' => Order::REJECTED_BY_ROLE_SALES_MANAGER,
            'rejected_at' => Carbon::now('Asia/Kolkata'),
            'rejection_remark' => trim($remark),
        ]);
        app(\App\Services\Dealers\DealerLedgerPostingService::class)->removeSourceCreditOrder($source);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orderItemsFromCreditNote(CreditNote $creditNote): array
    {
        $items = [];
        foreach ($creditNote->items as $item) {
            $items[] = [
                'product_id' => (int) $item->product_id,
                'case_quantity' => (int) ($item->case_quantity ?? 1),
                'nos_per_case' => (int) ($item->nos_per_case ?? 1),
                'total_quantity_nos' => (int) ($item->total_quantity_nos ?? round((float) $item->quantity)),
                'quantity' => (float) $item->quantity,
                'unit' => $item->product?->uom,
                'rate_per_no' => (float) ($item->rate_per_no ?? $item->rate ?? 0),
                'rate_type' => $item->rate_type ?: 'price_list',
                'rate' => (float) ($item->rate_per_no ?? $item->rate ?? 0),
                'discount_percentage' => (float) ($item->discount_percentage ?? 0),
                'discount_amount' => (float) ($item->discount_amount ?? 0),
                'gst_percentage' => (float) ($item->gst_percentage ?? 0),
                'base_amount' => (float) ($item->base_amount ?? 0),
                'taxable_amount' => (float) ($item->taxable_amount ?? 0),
                'gst_amount' => (float) ($item->gst_amount ?? 0),
                'final_amount' => (float) ($item->final_amount ?? $item->amount),
                'line_total' => (float) ($item->final_amount ?? $item->amount),
            ];
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{subtotal: float, discount_amount: float, gst_amount: float, grand_total: float, unrounded_grand_total: float, round_off: float}
     */
    private function summarize(array $items): array
    {
        $subtotal = 0.0;
        $totalDiscount = 0.0;
        $totalGst = 0.0;

        foreach ($items as $item) {
            $subtotal += (float) $item['base_amount'];
            $totalDiscount += (float) $item['discount_amount'];
            $totalGst += (float) $item['gst_amount'];
        }

        return [
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($totalDiscount, 2),
            'gst_amount' => round($totalGst, 2),
            ...OrderBillingTransportCalculator::persistableRoundedTotals(
                round($subtotal - $totalDiscount + $totalGst, 2),
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function persistItems(Order $order, array $items): void
    {
        foreach ($items as $item) {
            $order->items()->create($item);
        }
    }

    private function transferRemarks(CreditNote $creditNote): string
    {
        $from = $creditNote->dealer?->firm_name ?: 'returning dealer';

        return 'Sales return transfer from '.$from
            .' • '.$creditNote->credit_note_no
            .' • Bill '.$creditNote->bill_reference;
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
            : ((int) substr((string) $lastNumber, -4)) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
