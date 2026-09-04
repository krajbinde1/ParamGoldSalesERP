<?php

namespace App\Services\Dealers;

use App\Models\Collection;
use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class DealerLedgerPostingService
{
    public function __construct(
        private readonly DealerSalesLedgerReconciler $salesReconciler,
    ) {}

    public function syncDispatchedOrder(Order $order): ?DealerTallyEntry
    {
        if ($order->dealer_id === null || ! $order->isBilledReceivable()) {
            $this->removeUnreconciledSalesOrderLedgerEntry($order);

            return null;
        }

        $amount = round((float) $order->grand_total, 2);
        if ($amount <= 0.0) {
            $this->removeUnreconciledSalesOrderLedgerEntry($order);

            return null;
        }

        return $this->syncErpEntry(
            dealerId: (int) $order->dealer_id,
            source: DealerTallyEntry::SOURCE_SALES_ORDER,
            sourceId: (int) $order->id,
            date: $order->dealerLedgerEntryDate(),
            debit: $amount,
            credit: 0.0,
            particulars: 'Sales Order '.$order->order_no,
            voucherType: 'Sales',
            voucherNo: (string) $order->order_no,
        );
    }

    public function syncReceivedCollection(Collection $collection): ?DealerTallyEntry
    {
        if ($collection->status !== Collection::STATUS_RECEIVED || $collection->dealer_id === null) {
            $this->removeCollectionLedgerEntry($collection);

            return null;
        }

        $amount = round((float) $collection->amount, 2);
        $date = $collection->collection_date?->toDateString()
            ?: Carbon::now('Asia/Kolkata')->toDateString();
        $reference = filled($collection->receipt_no)
            ? (string) $collection->receipt_no
            : 'COL-'.$collection->id;

        return $this->syncErpEntry(
            dealerId: (int) $collection->dealer_id,
            source: DealerTallyEntry::SOURCE_COLLECTION,
            sourceId: (int) $collection->id,
            date: $date,
            debit: 0.0,
            credit: $amount,
            particulars: 'Payment Received / Collection',
            voucherType: 'Receipt',
            voucherNo: $reference,
        );
    }

    public function removeCollectionLedgerEntry(Collection $collection): void
    {
        DealerTallyEntry::query()
            ->where('source', DealerTallyEntry::SOURCE_COLLECTION)
            ->where(function ($query) use ($collection): void {
                $query->where('source_id', $collection->id)
                    ->orWhere('fingerprint', DealerTallyEntry::makeSourceFingerprint(
                        DealerTallyEntry::SOURCE_COLLECTION,
                        (int) $collection->id,
                    ));
            })
            ->delete();
    }

    /**
     * @return array{orders: int, collections: int, sales_reconciled: int}
     */
    public function backfill(): array
    {
        $orders = 0;
        $collections = 0;

        $statusList = implode(',', array_fill(0, count(Order::billedReceivableStatuses()), '?'));
        Order::query()
            ->whereRaw('LOWER(TRIM(status)) in ('.$statusList.')', Order::billedReceivableStatuses())
            ->orderBy('id')
            ->each(function (Order $order) use (&$orders): void {
                if ($this->syncDispatchedOrder($order) !== null) {
                    $orders++;
                }
            });

        Collection::query()
            ->where('status', Collection::STATUS_RECEIVED)
            ->orderBy('id')
            ->each(function (Collection $collection) use (&$collections): void {
                if ($this->syncReceivedCollection($collection) !== null) {
                    $collections++;
                }
            });

        return [
            'orders' => $orders,
            'collections' => $collections,
            'sales_reconciled' => $this->salesReconciler->reconcileExistingDuplicates(),
        ];
    }

    /**
     * Explain why a sales order would be skipped or posted. Does not write.
     *
     * @return array{
     *     order_id: int,
     *     order_no: string,
     *     short_order_no: string,
     *     dealer_id: int|null,
     *     dealer_name: string,
     *     status: string,
     *     grand_total: float,
     *     order_date: string|null,
     *     created_at: string|null,
     *     in_backfill_query: string,
     *     existing_ledger_match: string,
     *     skip_reason: string,
     *     action: string
     * }
     */
    public function diagnoseSalesOrder(Order $order, ?Dealer $expectedDealer = null): array
    {
        $order->loadMissing('dealer');
        $fingerprint = DealerTallyEntry::makeSourceFingerprint(
            DealerTallyEntry::SOURCE_SALES_ORDER,
            (int) $order->id,
        );
        $linked = DealerTallyEntry::query()
            ->where(function ($query) use ($fingerprint, $order): void {
                $query->where('fingerprint', $fingerprint)
                    ->orWhere('source_id', $order->id);
            })
            ->orderBy('id')
            ->get();

        $match = $linked->isEmpty()
            ? 'none'
            : $linked->map(function (DealerTallyEntry $entry) use ($order): string {
                $represents = $this->salesReconciler->entryRepresentsOrder($entry, $order);
                $foreign = $this->salesReconciler->entryIsForeignToOrder($entry, $order);
                $label = $represents ? 'REPRESENTS_ORDER' : ($foreign ? 'FOREIGN_CLAIM' : 'LINKED');

                return sprintf(
                    '#%d dealer_id=%s date=%s debit=%s voucher=%s tally_voucher=%s source=%s source_id=%s %s',
                    $entry->id,
                    $entry->dealer_id,
                    $entry->entry_date?->toDateString() ?? 'null',
                    number_format((float) $entry->debit, 2, '.', ''),
                    $entry->voucher_no ?: '—',
                    $entry->tally_voucher_no ?: '—',
                    $entry->source,
                    $entry->source_id ?? 'null',
                    $label,
                );
            })->implode(' ; ');

        $inQuery = ! $order->trashed() && $order->isBilledReceivable() ? 'yes' : 'no';
        $amount = round((float) $order->grand_total, 2);
        $dealerName = $order->dealer?->firm_name ?: '—';
        $action = 'post';
        $reason = 'will create Sales Order '.$order->shortOrderNo().' debit '.$amount;

        if ($order->trashed()) {
            $action = 'skip';
            $reason = 'soft-deleted; backfill query does not select this order';
        } elseif ($order->dealer_id === null) {
            $action = 'skip';
            $reason = 'dealer_id is null';
        } elseif (! $order->isBilledReceivable()) {
            $action = 'skip';
            $reason = 'status ['.$order->status.'] is not billed/dispatched/delivered; backfill query does not select this order';
        } elseif ($amount <= 0.0) {
            $action = 'skip';
            $reason = 'grand_total is '.$amount.'; debit not posted';
        } elseif ($linked->contains(fn (DealerTallyEntry $entry): bool => $this->salesReconciler->entryRepresentsOrder($entry, $order))) {
            $action = 'already_posted';
            $reason = 'already posted (source=sales_order and source_id/fingerprint match this order)';
        } elseif ($linked->contains(fn (DealerTallyEntry $entry): bool => $this->salesReconciler->entryIsForeignToOrder($entry, $order))) {
            $action = 'post';
            $reason = 'existing ledger row is incorrectly linked to this order id (amount/voucher do not match). Will release that mapping and create a new '.$amount.' debit. Will not change the foreign row debit/date/voucher.';
        } else {
            $tally = $this->salesReconciler->findMatchingTallySalesEntry($order);
            if ($tally !== null) {
                $reason = 'will attach Tally sales #'.$tally->id.' because voucher/reference names this ERP order';
            }
        }

        if ($expectedDealer !== null && $order->dealer_id !== null
            && (int) $order->dealer_id !== (int) $expectedDealer->id) {
            $reason .= ' | dealer mismatch: order dealer_id='.$order->dealer_id.' ('.$dealerName.') vs ledger dealer_id='.$expectedDealer->id.' ('.$expectedDealer->firm_name.')';
        }

        return [
            'order_id' => (int) $order->id,
            'order_no' => (string) $order->order_no,
            'short_order_no' => $order->shortOrderNo(),
            'dealer_id' => $order->dealer_id !== null ? (int) $order->dealer_id : null,
            'dealer_name' => $dealerName,
            'status' => (string) $order->status,
            'grand_total' => $amount,
            'order_date' => $order->order_date?->toDateString(),
            'created_at' => $order->created_at?->timezone('Asia/Kolkata')->toDateTimeString(),
            'in_backfill_query' => $inQuery,
            'existing_ledger_match' => $match,
            'skip_reason' => $reason,
            'action' => $action,
        ];
    }

    public function matchingSideExists(
        int $dealerId,
        string $date,
        float $debit,
        float $credit,
        ?int $exceptId = null,
    ): bool {
        $debit = round($debit, 2);
        $credit = round($credit, 2);
        $date = Carbon::parse($date)->timezone('Asia/Kolkata')->toDateString();

        if ($debit <= 0.0 && $credit <= 0.0) {
            return false;
        }

        $query = DealerTallyEntry::query()
            ->where('dealer_id', $dealerId)
            ->whereDate('entry_date', $date)
            ->whereRaw('ABS(COALESCE(debit, 0) - ?) < 0.005', [$debit])
            ->whereRaw('ABS(COALESCE(credit, 0) - ?) < 0.005', [$credit]);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->exists();
    }

    private function syncErpEntry(
        int $dealerId,
        string $source,
        int $sourceId,
        string $date,
        float $debit,
        float $credit,
        string $particulars,
        string $voucherType,
        string $voucherNo,
    ): ?DealerTallyEntry {
        $fingerprint = DealerTallyEntry::makeSourceFingerprint($source, $sourceId);

        return DB::transaction(function () use (
            $dealerId,
            $source,
            $sourceId,
            $date,
            $debit,
            $credit,
            $particulars,
            $voucherType,
            $voucherNo,
            $fingerprint,
        ): ?DealerTallyEntry {
            $existing = $this->lockedExistingErpEntry($source, $sourceId, $fingerprint);
            $order = $source === DealerTallyEntry::SOURCE_SALES_ORDER
                ? Order::query()->find($sourceId)
                : null;

            if ($existing !== null && $order instanceof Order) {
                $represents = $this->salesReconciler->entryRepresentsOrder($existing, $order);
                $foreign = $this->salesReconciler->entryIsForeignToOrder($existing, $order);
                if (! $represents && ($foreign || $this->salesReconciler->isReconciled($existing))) {
                    $this->salesReconciler->releaseOrderClaim($existing, $order);
                    $existing = null;
                }
            }

            if ($existing !== null) {
                if ($this->salesReconciler->isReconciled($existing)) {
                    $existing->fill([
                        'dealer_id' => $dealerId,
                        'source' => $source,
                        'source_id' => $sourceId,
                        'fingerprint' => $fingerprint,
                    ]);
                    $existing->save();

                    return $existing;
                }

                $existing->fill([
                    'dealer_id' => $dealerId,
                    'entry_date' => $date,
                    'particulars' => $particulars,
                    'voucher_type' => $voucherType,
                    'voucher_no' => $voucherNo,
                    'debit' => $debit,
                    'credit' => $credit,
                    'source' => $source,
                    'source_id' => $sourceId,
                    'fingerprint' => $fingerprint,
                ]);
                $existing->save();

                return $existing;
            }

            if ($order instanceof Order) {
                $tallySales = $this->salesReconciler->findMatchingTallySalesEntry($order);
                if ($tallySales !== null) {
                    $attached = $this->salesReconciler->attachSalesOrderToTallyEntry($tallySales, $order);
                    if ($attached !== null) {
                        return $attached;
                    }
                }
            }

            // Collections still skip a same-date/same-side duplicate (ERP vs Tally receipt).
            // Sales orders must never be skipped for that reason: another 31 Aug debit of
            // the same amount is a different voucher, not this order.
            if ($source !== DealerTallyEntry::SOURCE_SALES_ORDER
                && $this->matchingSideExists($dealerId, $date, $debit, $credit)) {
                return null;
            }

            $payload = [
                'dealer_id' => $dealerId,
                'import_id' => null,
                'entry_date' => $date,
                'particulars' => $particulars,
                'voucher_type' => $voucherType,
                'voucher_no' => $voucherNo,
                'debit' => $debit,
                'credit' => $credit,
                'source' => $source,
                'source_id' => $sourceId,
                'fingerprint' => $fingerprint,
                'source_row' => null,
            ];

            try {
                return DealerTallyEntry::query()->create($payload);
            } catch (UniqueConstraintViolationException $exception) {
                $conflict = DealerTallyEntry::query()
                    ->where('fingerprint', $fingerprint)
                    ->lockForUpdate()
                    ->first();
                if ($conflict !== null && $order instanceof Order) {
                    if ($this->salesReconciler->entryRepresentsOrder($conflict, $order)) {
                        return $conflict;
                    }
                    if ($this->salesReconciler->entryIsForeignToOrder($conflict, $order)
                        || ((int) $conflict->source_id === (int) $order->id)) {
                        $this->salesReconciler->releaseOrderClaim($conflict, $order);
                        $payload['fingerprint'] = $fingerprint;

                        return DealerTallyEntry::query()->create($payload);
                    }
                }

                throw $exception;
            }
        });
    }

    private function removeUnreconciledSalesOrderLedgerEntry(Order $order): void
    {
        DealerTallyEntry::query()
            ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
            ->where(function ($query) use ($order): void {
                $query->where('source_id', $order->id)
                    ->orWhere('fingerprint', DealerTallyEntry::makeSourceFingerprint(
                        DealerTallyEntry::SOURCE_SALES_ORDER,
                        (int) $order->id,
                    ));
            })
            ->get()
            ->each(function (DealerTallyEntry $entry): void {
                if ($this->salesReconciler->isReconciled($entry)) {
                    return;
                }

                $entry->delete();
            });
    }

    private function lockedExistingErpEntry(string $source, int $sourceId, string $fingerprint): ?DealerTallyEntry
    {
        return DealerTallyEntry::query()
            ->where(function ($query) use ($fingerprint, $source, $sourceId): void {
                $query->where('fingerprint', $fingerprint)
                    ->orWhere(function ($inner) use ($source, $sourceId): void {
                        $inner->where('source', $source)->where('source_id', $sourceId);
                    });
            })
            ->lockForUpdate()
            ->orderByRaw('CASE WHEN fingerprint = ? THEN 0 ELSE 1 END', [$fingerprint])
            ->first();
    }
}
