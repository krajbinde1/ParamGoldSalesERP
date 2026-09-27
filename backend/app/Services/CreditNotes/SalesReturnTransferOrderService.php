<?php

namespace App\Services\CreditNotes;

use App\Models\CreditNote;
use App\Models\Dealer;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderBillingTransportCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class SalesReturnTransferOrderService
{
    public function syncLinkedOrder(CreditNote $creditNote): ?Order
    {
        if (! $creditNote->isMoveToDealer()) {
            $this->withdrawUnsyncedLinkedOrder($creditNote);

            return null;
        }

        $destination = $creditNote->destinationDealer ?? Dealer::query()->find($creditNote->destination_dealer_id);
        if ($destination === null) {
            throw ValidationException::withMessages([
                'destination_dealer_id' => ['Select the destination dealer for this return.'],
            ]);
        }

        if ((int) $destination->id === (int) $creditNote->dealer_id) {
            throw ValidationException::withMessages([
                'destination_dealer_id' => ['Destination dealer must be different from the returning dealer.'],
            ]);
        }

        $creditNote->loadMissing('items', 'dealer');
        $calculatedItems = $this->orderItemsFromCreditNote($creditNote);
        $totals = $this->summarize($calculatedItems);
        $remarks = $this->transferRemarks($creditNote);

        $existing = $this->existingLinkedOrder($creditNote);
        if ($existing !== null) {
            if (! in_array($existing->status, [
                Order::STATUS_PENDING_APPROVAL,
                Order::STATUS_REJECTED,
            ], true)) {
                return $existing;
            }

            $existing->items()->delete();
            $existing->update([
                'dealer_id' => $destination->id,
                'remarks' => $remarks,
                'status' => Order::STATUS_PENDING_APPROVAL,
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
            $this->persistItems($existing, $calculatedItems);
            $creditNote->update(['linked_order_id' => $existing->id]);

            return $existing->fresh(['items']);
        }

        $order = Order::query()->create([
            'order_no' => $this->generateOrderNumber(),
            'order_date' => Order::businessToday(),
            'dealer_id' => $destination->id,
            'sales_employee_id' => $creditNote->sales_employee_id,
            'source_credit_note_id' => $creditNote->id,
            'remarks' => $remarks,
            'status' => Order::STATUS_PENDING_APPROVAL,
            'payment_type' => 'Credit',
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'gst_amount' => $totals['gst_amount'],
            'grand_total' => $totals['grand_total'],
            'unrounded_grand_total' => $totals['unrounded_grand_total'],
            'round_off' => $totals['round_off'],
        ]);

        $this->persistItems($order, $calculatedItems);
        $creditNote->update(['linked_order_id' => $order->id]);

        return $order->fresh(['items']);
    }

    public function sendApprovedTransferToBilling(CreditNote $creditNote, ?int $userId): void
    {
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
    }

    private function withdrawUnsyncedLinkedOrder(CreditNote $creditNote): void
    {
        $order = $this->existingLinkedOrder($creditNote);
        if ($order === null) {
            $creditNote->update(['linked_order_id' => null]);

            return;
        }

        if ($order->status === Order::STATUS_PENDING_APPROVAL) {
            $order->items()->delete();
            $order->delete();
        }

        $creditNote->update(['linked_order_id' => null]);
    }

    private function existingLinkedOrder(CreditNote $creditNote): ?Order
    {
        if ($creditNote->linked_order_id) {
            return Order::query()->find($creditNote->linked_order_id);
        }

        return Order::query()->where('source_credit_note_id', $creditNote->id)->first();
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
