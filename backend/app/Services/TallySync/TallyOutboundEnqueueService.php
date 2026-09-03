<?php

namespace App\Services\TallySync;

use App\Models\Collection;
use App\Models\Dealer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TallyDealerMapping;
use App\Models\TallyOutboundVoucher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TallyOutboundEnqueueService
{
    public const ERROR_NO_DEALER = 'This record has no dealer, so it cannot be sent to Tally.';

    public const ERROR_NO_MAPPING = 'Dealer has no Tally ledger mapping. Map this dealer in ERP before the voucher can be sent to Tally.';

    public const ERROR_MULTIPLE_MAPPINGS = 'Dealer has more than one Tally ledger mapping. Keep a single mapping; the connector will not guess a ledger.';

    public function queueBilledOrder(Order $order): ?TallyOutboundVoucher
    {
        if ($order->status === Order::STATUS_REJECTED) {
            $this->withdrawUnsynced(
                TallyOutboundVoucher::SOURCE_SALES_ORDER,
                (int) $order->id,
                'Order was rejected, so it must not be sent to Tally.',
            );

            return null;
        }

        if ($order->status !== Order::STATUS_BILLED) {
            return null;
        }

        $order->loadMissing(['dealer', 'items.product']);

        $mapping = $this->resolveMapping($order->dealer);
        $payload = $this->salesPayload($order, $mapping['ledger']);

        return $this->insertOnce(
            sourceType: TallyOutboundVoucher::SOURCE_SALES_ORDER,
            sourceId: (int) $order->id,
            voucherType: TallyOutboundVoucher::VOUCHER_SALES,
            erpReference: TallyOutboundVoucher::salesReference((int) $order->id),
            payload: $payload,
            mappingError: $mapping['error'],
        );
    }

    public function queueReceivedCollection(Collection $collection): ?TallyOutboundVoucher
    {
        if ($collection->status !== Collection::STATUS_RECEIVED) {
            $this->withdrawUnsynced(
                TallyOutboundVoucher::SOURCE_COLLECTION,
                (int) $collection->id,
                'Collection is no longer Received, so it must not be sent to Tally.',
            );

            return null;
        }

        $collection->loadMissing('dealer');

        $mapping = $this->resolveMapping($collection->dealer);
        $payload = $this->receiptPayload($collection, $mapping['ledger']);

        return $this->insertOnce(
            sourceType: TallyOutboundVoucher::SOURCE_COLLECTION,
            sourceId: (int) $collection->id,
            voucherType: TallyOutboundVoucher::VOUCHER_RECEIPT,
            erpReference: TallyOutboundVoucher::receiptReference((int) $collection->id),
            payload: $payload,
            mappingError: $mapping['error'],
            refreshIfUnsynced: true,
        );
    }

    /**
     * @return array{ledger: ?string, error: ?string}
     */
    private function resolveMapping(?Dealer $dealer): array
    {
        if ($dealer === null) {
            return ['ledger' => null, 'error' => self::ERROR_NO_DEALER];
        }

        $mappings = TallyDealerMapping::query()
            ->where('dealer_id', $dealer->id)
            ->orderBy('id')
            ->get();

        if ($mappings->isEmpty()) {
            return ['ledger' => null, 'error' => self::ERROR_NO_MAPPING];
        }

        if ($mappings->count() > 1) {
            return ['ledger' => null, 'error' => self::ERROR_MULTIPLE_MAPPINGS];
        }

        $name = trim((string) $mappings->first()?->tally_ledger_name);
        if ($name === '') {
            return ['ledger' => null, 'error' => self::ERROR_NO_MAPPING];
        }

        return ['ledger' => $name, 'error' => null];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function insertOnce(
        string $sourceType,
        int $sourceId,
        string $voucherType,
        string $erpReference,
        array $payload,
        ?string $mappingError,
        bool $refreshIfUnsynced = false,
    ): TallyOutboundVoucher {
        try {
            return DB::transaction(function () use (
                $sourceType,
                $sourceId,
                $voucherType,
                $erpReference,
                $payload,
                $mappingError,
                $refreshIfUnsynced,
            ): TallyOutboundVoucher {
                $existing = TallyOutboundVoucher::query()
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->lockForUpdate()
                    ->first();

                $ready = $mappingError === null;
                $status = $ready
                    ? TallyOutboundVoucher::STATUS_PENDING
                    : TallyOutboundVoucher::STATUS_FAILED;

                if ($existing !== null) {
                    if ($existing->isSynced() || ! $refreshIfUnsynced || ! $existing->isFailed()) {
                        return $existing;
                    }

                    $existing->fill([
                        'payload' => $payload,
                        'status' => $status,
                        'last_error' => $mappingError,
                        'claimed_at' => null,
                        'claimed_until' => null,
                        'claimed_by' => null,
                    ]);
                    $existing->save();

                    return $existing;
                }

                return TallyOutboundVoucher::query()->create([
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'voucher_type' => $voucherType,
                    'erp_reference' => $erpReference,
                    'payload' => $payload,
                    'status' => $status,
                    'attempts' => 0,
                    'last_error' => $mappingError,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return TallyOutboundVoucher::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->firstOrFail();
        }
    }

    private function withdrawUnsynced(string $sourceType, int $sourceId, string $error): void
    {
        TallyOutboundVoucher::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereIn('status', [
                TallyOutboundVoucher::STATUS_PENDING,
                TallyOutboundVoucher::STATUS_CLAIMED,
            ])
            ->update([
                'status' => TallyOutboundVoucher::STATUS_FAILED,
                'last_error' => $error,
                'claimed_until' => null,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function salesPayload(Order $order, ?string $tallyLedgerName): array
    {
        $billDate = $order->bill_date?->toDateString()
            ?? $order->billed_at?->timezone('Asia/Kolkata')?->toDateString()
            ?? Carbon::now('Asia/Kolkata')->toDateString();

        return [
            'erp_reference' => TallyOutboundVoucher::salesReference((int) $order->id),
            'voucher_type' => TallyOutboundVoucher::VOUCHER_SALES,
            'date' => $billDate,
            'party' => $this->partyPayload($order->dealer, $tallyLedgerName),
            'order' => [
                'id' => (int) $order->id,
                'order_no' => (string) $order->order_no,
                'bill_number' => filled($order->bill_number) ? (string) $order->bill_number : null,
                'bill_date' => $order->bill_date?->toDateString(),
                'billed_at' => $order->billed_at?->timezone('Asia/Kolkata')?->toDateTimeString(),
                'subtotal' => $this->money($order->subtotal),
                'discount_amount' => $this->money($order->discount_amount),
                'gst_amount' => $this->money($order->gst_amount),
                'round_off' => $this->money($order->round_off),
                'grand_total' => $this->money($order->grand_total),
                'transport_amount' => $this->money($order->transport_amount),
                'transport_charge_type' => $order->transport_charge_type,
            ],
            'items' => $order->items
                ->map(fn (OrderItem $item): array => [
                    'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
                    'product_code' => $item->product?->product_code,
                    'product_name' => $item->product?->product_name,
                    'hsn_code' => $item->product?->hsn_code,
                    'uom' => $item->unit ?: $item->product?->uom,
                    'quantity' => round((float) ($item->quantity ?? 0), 3),
                    'rate' => $this->money($item->rate_per_no ?? $item->rate),
                    'discount_amount' => $this->money($item->discount_amount),
                    'gst_percentage' => $this->money($item->gst_percentage),
                    'taxable_amount' => $this->money($item->taxable_amount),
                    'gst_amount' => $this->money($item->gst_amount),
                    'line_total' => $this->money($item->final_amount ?? $item->line_total),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptPayload(Collection $collection, ?string $tallyLedgerName): array
    {
        $date = $collection->collection_date?->toDateString()
            ?: Carbon::now('Asia/Kolkata')->toDateString();
        $receiptNo = filled($collection->receipt_no)
            ? (string) $collection->receipt_no
            : 'COL-'.$collection->id;

        return [
            'erp_reference' => TallyOutboundVoucher::receiptReference((int) $collection->id),
            'voucher_type' => TallyOutboundVoucher::VOUCHER_RECEIPT,
            'date' => $date,
            'party' => $this->partyPayload($collection->dealer, $tallyLedgerName),
            'collection' => [
                'id' => (int) $collection->id,
                'receipt_no' => $receiptNo,
                'collection_date' => $date,
                'amount' => $this->money($collection->amount),
                'payment_mode' => filled($collection->payment_mode)
                    ? (string) $collection->payment_mode
                    : 'Cash',
                'bank_name' => filled($collection->bank_name) ? (string) $collection->bank_name : null,
                'transaction_number' => filled($collection->transaction_number)
                    ? (string) $collection->transaction_number
                    : null,
                'remarks' => filled($collection->remarks) ? (string) $collection->remarks : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function partyPayload(?Dealer $dealer, ?string $tallyLedgerName): array
    {
        return [
            'dealer_id' => $dealer?->id !== null ? (int) $dealer->id : null,
            'dealer_code' => $dealer?->dealer_code,
            'firm_name' => $dealer?->firm_name,
            'gst_no' => $dealer?->gst_no,
            'state' => $dealer?->state,
            'tally_ledger_name' => $tallyLedgerName,
        ];
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
