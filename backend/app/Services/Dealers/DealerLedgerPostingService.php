<?php

namespace App\Services\Dealers;

use App\Models\Collection;
use App\Models\DealerTallyEntry;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class DealerLedgerPostingService
{
    public function syncDispatchedOrder(Order $order): ?DealerTallyEntry
    {
        if ($order->status !== Order::STATUS_DISPATCHED || $order->dealer_id === null) {
            return null;
        }

        $amount = round((float) $order->grand_total, 2);
        $date = $this->orderEntryDate($order);

        return $this->syncErpEntry(
            dealerId: (int) $order->dealer_id,
            source: DealerTallyEntry::SOURCE_SALES_ORDER,
            sourceId: (int) $order->id,
            date: $date,
            debit: $amount,
            credit: 0.0,
            particulars: 'Sales Order',
            voucherType: 'Sales',
            voucherNo: (string) $order->order_no,
        );
    }

    public function syncReceivedCollection(Collection $collection): ?DealerTallyEntry
    {
        if ($collection->status !== Collection::STATUS_RECEIVED || $collection->dealer_id === null) {
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

    /**
     * @return array{orders: int, collections: int}
     */
    public function backfill(): array
    {
        $orders = 0;
        $collections = 0;

        Order::query()
            ->where('status', Order::STATUS_DISPATCHED)
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
            ->whereDate('entry_date', $date);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        // Date + amount + side. Debit matches debit only; credit matches credit only.
        // ABS(...) is used because SQLite ROUND(column, 2) = bound float does not match.
        if ($debit > 0.0 && $credit == 0.0) {
            return $query
                ->whereRaw('ABS(COALESCE(debit, 0) - ?) < 0.005', [$debit])
                ->whereRaw('ABS(COALESCE(credit, 0)) < 0.005')
                ->exists();
        }

        if ($credit > 0.0 && $debit == 0.0) {
            return $query
                ->whereRaw('ABS(COALESCE(credit, 0) - ?) < 0.005', [$credit])
                ->whereRaw('ABS(COALESCE(debit, 0)) < 0.005')
                ->exists();
        }

        return false;
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
            $existing = DealerTallyEntry::query()
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->fill([
                    'entry_date' => $date,
                    'particulars' => $particulars,
                    'voucher_type' => $voucherType,
                    'voucher_no' => $voucherNo,
                    'debit' => $debit,
                    'credit' => $credit,
                    'source' => $source,
                    'source_id' => $sourceId,
                ]);
                $existing->save();

                return $existing;
            }

            if ($this->matchingSideExists($dealerId, $date, $debit, $credit)) {
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

    private function orderEntryDate(Order $order): string
    {
        return $order->dispatch_date?->toDateString()
            ?? $order->dispatched_at?->timezone('Asia/Kolkata')?->toDateString()
            ?? $order->order_date?->toDateString()
            ?? Carbon::now('Asia/Kolkata')->toDateString();
    }
}
