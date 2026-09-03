<?php

namespace App\Services\Dealers;

use App\Models\Collection;
use App\Models\DealerTallyEntry;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class DealerLedgerPostingService
{
    public function __construct(
        private readonly DealerSalesLedgerReconciler $salesReconciler,
    ) {}

    public function syncDispatchedOrder(Order $order): ?DealerTallyEntry
    {
        if ($order->dealer_id === null || ! in_array($order->status, Order::billedReceivableStatuses(), true)) {
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
            particulars: 'Sales Order '.$order->shortOrderNo(),
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

        Order::query()
            ->whereIn('status', Order::billedReceivableStatuses())
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

            if ($source === DealerTallyEntry::SOURCE_SALES_ORDER) {
                $order = Order::query()->find($sourceId);
                if ($order instanceof Order) {
                    $tallySales = $this->salesReconciler->findMatchingTallySalesEntry($order);
                    if ($tallySales !== null) {
                        return $this->salesReconciler->attachSalesOrderToTallyEntry($tallySales, $order);
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

            return DealerTallyEntry::query()->create([
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
            ]);
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
