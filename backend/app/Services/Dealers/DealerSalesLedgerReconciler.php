<?php

namespace App\Services\Dealers;

use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Models\Order;
use App\Services\TallyLedger\TallyLedgerConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class DealerSalesLedgerReconciler
{
    /**
     * @param  array<string, mixed>  $transaction
     */
    public function isTallySalesDebit(array $transaction): bool
    {
        return $this->isSalesDebit(
            debit: (float) ($transaction['debit'] ?? 0),
            credit: (float) ($transaction['credit'] ?? 0),
            voucherType: (string) ($transaction['voucher_type'] ?? ''),
            particulars: (string) ($transaction['particulars'] ?? ''),
        );
    }

    public function isSalesDebitEntry(DealerTallyEntry $entry): bool
    {
        return $this->isSalesDebit(
            debit: (float) $entry->debit,
            credit: (float) $entry->credit,
            voucherType: (string) ($entry->voucher_type ?? ''),
            particulars: (string) ($entry->particulars ?? ''),
        );
    }

    public function isReconciled(DealerTallyEntry $entry): bool
    {
        return $entry->tally_reconciled_at !== null
            || filled($entry->tally_voucher_no);
    }

    /**
     * Exact Tally duplicate: same dealer, date, debit, credit, and voucher number.
     *
     * @param  array<string, mixed>  $transaction
     */
    public function tallyDuplicateExists(int $dealerId, array $transaction, string $fingerprint): bool
    {
        if (DealerTallyEntry::query()->where('fingerprint', $fingerprint)->exists()) {
            return true;
        }

        $date = Carbon::parse((string) $transaction['date'])->timezone('Asia/Kolkata')->toDateString();
        $debit = round((float) $transaction['debit'], 2);
        $credit = round((float) $transaction['credit'], 2);
        $voucherNo = $this->normalizeVoucherNo((string) ($transaction['voucher_no'] ?? ''));

        $query = DealerTallyEntry::query()
            ->where('dealer_id', $dealerId)
            ->whereDate('entry_date', $date)
            ->whereRaw('ABS(COALESCE(debit, 0) - ?) < 0.005', [$debit])
            ->whereRaw('ABS(COALESCE(credit, 0) - ?) < 0.005', [$credit]);

        if ($voucherNo === '') {
            $query->where(function ($empty): void {
                $empty->whereNull('voucher_no')->orWhere('voucher_no', '');
            })->where(function ($empty): void {
                $empty->whereNull('tally_voucher_no')->orWhere('tally_voucher_no', '');
            });
        } else {
            $query->where(function ($inner) use ($voucherNo): void {
                $inner->whereRaw("UPPER(REPLACE(COALESCE(voucher_no, ''), ' ', '')) = ?", [$voucherNo])
                    ->orWhereRaw("UPPER(REPLACE(COALESCE(tally_voucher_no, ''), ' ', '')) = ?", [$voucherNo]);
            });
        }

        return $query->exists();
    }

    /**
     * Unique unreconciled ERP Sales Order debit for this dealer and amount.
     * Does not match collections, receipts, credit notes, or unrelated same-amount rows.
     */
    public function findMatchingSalesOrderEntry(int $dealerId, float $debit, string $tallyDate): ?DealerTallyEntry
    {
        $debit = round($debit, 2);
        if ($debit <= 0.0) {
            return null;
        }

        $candidates = $this->unreconciledSalesOrderEntries($dealerId, $debit);
        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        return $this->uniquelyClosest($candidates, $tallyDate);
    }

    /**
     * Unique unreconciled Tally sales debit that can be attached to this dispatched order.
     */
    public function findMatchingTallySalesEntry(Order $order): ?DealerTallyEntry
    {
        if ($order->dealer_id === null) {
            return null;
        }

        $debit = round((float) $order->grand_total, 2);
        if ($debit <= 0.0) {
            return null;
        }

        $candidates = $this->unreconciledTallySalesEntries((int) $order->dealer_id, $debit)
            ->filter(fn (DealerTallyEntry $entry): bool => ! $this->refersToDifferentOrder($entry, $order))
            ->values();
        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $orderDate = $order->dealerLedgerEntryDate();

        return $this->uniquelyClosest($candidates, $orderDate);
    }

    private function uniqueOwnerOrderForEntry(DealerTallyEntry $entry, int $exceptOrderId): ?Order
    {
        $debit = round((float) $entry->debit, 2);
        if ($debit <= 0.0 || abs((float) $entry->credit) >= 0.005) {
            return null;
        }

        $matches = Order::query()
            ->where('dealer_id', $entry->dealer_id)
            ->whereIn('status', Order::billedReceivableStatuses())
            ->whereKeyNot($exceptOrderId)
            ->whereRaw('ABS(COALESCE(grand_total, 0) - ?) < 0.005', [$debit])
            ->get();

        if ($matches->count() !== 1) {
            return null;
        }

        return $matches->first();
    }

    private function amountsMatch(DealerTallyEntry $entry, float $debit, float $credit): bool
    {
        return abs(round((float) $entry->debit, 2) - round($debit, 2)) < 0.005
            && abs(round((float) $entry->credit, 2) - round($credit, 2)) < 0.005;
    }

    /**
     * Voucher / particulars name a different short sales order (PG-0015) than $order (PG-0001).
     * Full ERP numbers like PG-20260831-0001 must not be split into a fake PG-20260831 token.
     */
    public function refersToDifferentOrder(DealerTallyEntry $entry, Order $order): bool
    {
        $haystack = strtoupper(trim(implode(' ', array_filter([
            (string) $entry->voucher_no,
            (string) $entry->tally_voucher_no,
            (string) $entry->particulars,
        ]))));

        if ($haystack === '' || preg_match_all('/\bPG-\d+\b/', $haystack, $matches) === 0) {
            return false;
        }

        $short = strtoupper((string) $order->shortOrderNo());
        $full = strtoupper((string) $order->order_no);
        $sequence = preg_match('/^PG-\d{8}-(\d+)$/', $full, $parts) === 1
            ? $parts[1]
            : null;
        $own = array_unique(array_filter([$short, $full, $sequence !== null ? 'PG-'.$sequence : null]));

        foreach ($matches[0] as $token) {
            if (in_array($token, $own, true) || preg_match('/^PG-\d{8}$/', $token) === 1) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function reconcileSalesOrderWithTally(
        DealerTallyEntry $salesOrderEntry,
        array $transaction,
        ?int $importId,
    ): DealerTallyEntry {
        $tallyDate = Carbon::parse((string) $transaction['date'])->timezone('Asia/Kolkata')->toDateString();
        $particulars = trim((string) ($transaction['particulars'] ?? ''));
        $voucherType = trim((string) ($transaction['voucher_type'] ?? ''));
        $voucherNo = trim((string) ($transaction['voucher_no'] ?? ''));

        $salesOrderEntry->fill([
            'entry_date' => $tallyDate,
            'particulars' => $particulars !== '' ? $particulars : $salesOrderEntry->particulars,
            'voucher_type' => $voucherType !== '' ? $voucherType : $salesOrderEntry->voucher_type,
            'voucher_no' => $voucherNo !== '' ? $voucherNo : $salesOrderEntry->voucher_no,
            'import_id' => $importId ?? $salesOrderEntry->import_id,
            'tally_voucher_type' => $voucherType !== '' ? $voucherType : null,
            'tally_voucher_no' => $voucherNo !== '' ? $voucherNo : null,
            'tally_entry_date' => $tallyDate,
            'tally_reconciled_at' => Carbon::now('Asia/Kolkata'),
        ]);
        $salesOrderEntry->save();

        return $salesOrderEntry;
    }

    public function attachSalesOrderToTallyEntry(DealerTallyEntry $tallyEntry, Order $order): ?DealerTallyEntry
    {
        if (! $this->amountsMatch($tallyEntry, round((float) $order->grand_total, 2), 0.0)
            || $this->refersToDifferentOrder($tallyEntry, $order)) {
            return null;
        }

        $tallyEntry->fill([
            'source' => DealerTallyEntry::SOURCE_SALES_ORDER,
            'source_id' => (int) $order->id,
            'fingerprint' => DealerTallyEntry::makeSourceFingerprint(
                DealerTallyEntry::SOURCE_SALES_ORDER,
                (int) $order->id,
            ),
            'tally_voucher_type' => $tallyEntry->tally_voucher_type ?: $tallyEntry->voucher_type,
            'tally_voucher_no' => $tallyEntry->tally_voucher_no ?: $tallyEntry->voucher_no,
            'tally_entry_date' => $tallyEntry->tally_entry_date?->toDateString()
                ?: $tallyEntry->entry_date?->toDateString(),
            'tally_reconciled_at' => Carbon::now('Asia/Kolkata'),
        ]);
        $tallyEntry->save();

        return $tallyEntry;
    }

    /**
     * True when this ledger row is the posted debit for this sales order
     * (same dealer, same order id/fingerprint, same grand total). A Tally bill
     * for a different voucher/amount must never count as this order.
     */
    public function entryRepresentsOrder(DealerTallyEntry $entry, Order $order): bool
    {
        return ! $this->entryIsForeignToOrder($entry, $order)
            && $this->amountsMatch($entry, round((float) $order->grand_total, 2), 0.0)
            && ((int) $entry->source_id === (int) $order->id
                || $entry->fingerprint === DealerTallyEntry::makeSourceFingerprint(
                    DealerTallyEntry::SOURCE_SALES_ORDER,
                    (int) $order->id,
                ));
    }

    /**
     * A row claimed by this order's source_id/fingerprint that is actually another
     * voucher (different Tally bill / amount). Unreconciled ERP rows whose grand
     * total was edited are not foreign — they should be updated in place.
     */
    public function entryIsForeignToOrder(DealerTallyEntry $entry, Order $order): bool
    {
        $fingerprint = DealerTallyEntry::makeSourceFingerprint(
            DealerTallyEntry::SOURCE_SALES_ORDER,
            (int) $order->id,
        );
        $pointsAtOrder = (int) $entry->source_id === (int) $order->id
            || $entry->fingerprint === $fingerprint;

        if (! $pointsAtOrder) {
            return false;
        }

        if ((int) $entry->dealer_id !== (int) $order->dealer_id) {
            return true;
        }

        if ($this->refersToDifferentOrder($entry, $order)) {
            return true;
        }

        return $this->isReconciled($entry)
            && ! $this->amountsMatch($entry, round((float) $order->grand_total, 2), 0.0);
    }

    /**
     * Drop this order's source_id/fingerprint from a foreign ledger row.
     * Never changes debit, credit, date, particulars, or voucher text.
     */
    public function releaseOrderClaim(DealerTallyEntry $entry, Order $claimedBy): void
    {
        $claimedFingerprint = DealerTallyEntry::makeSourceFingerprint(
            DealerTallyEntry::SOURCE_SALES_ORDER,
            (int) $claimedBy->id,
        );

        if ((int) $entry->source_id !== (int) $claimedBy->id && $entry->fingerprint !== $claimedFingerprint) {
            return;
        }

        $owner = $this->uniqueOwnerOrderForEntry($entry, exceptOrderId: (int) $claimedBy->id);

        if ($owner !== null) {
            $ownerFingerprint = DealerTallyEntry::makeSourceFingerprint(
                DealerTallyEntry::SOURCE_SALES_ORDER,
                (int) $owner->id,
            );
            $ownerAlreadyPosted = DealerTallyEntry::query()
                ->whereKeyNot($entry->id)
                ->where(function ($query) use ($owner, $ownerFingerprint): void {
                    $query->where('fingerprint', $ownerFingerprint)
                        ->orWhere(function ($inner) use ($owner): void {
                            $inner->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
                                ->where('source_id', $owner->id);
                        });
                })
                ->exists();

            if (! $ownerAlreadyPosted) {
                $entry->fill([
                    'source' => DealerTallyEntry::SOURCE_SALES_ORDER,
                    'source_id' => (int) $owner->id,
                    'fingerprint' => $ownerFingerprint,
                ]);
                $entry->save();

                return;
            }
        }

        $voucherNo = (string) ($entry->tally_voucher_no ?: $entry->voucher_no ?: '');
        $entry->fill([
            'source' => TallyLedgerConfig::SOURCE,
            'source_id' => null,
            'fingerprint' => DealerTallyEntry::makeFingerprint(
                dealerId: (int) $entry->dealer_id,
                date: $entry->entry_date?->toDateString() ?? Carbon::now('Asia/Kolkata')->toDateString(),
                voucherType: (string) ($entry->tally_voucher_type ?: $entry->voucher_type ?: ''),
                voucherNo: $voucherNo,
                debit: (float) $entry->debit,
                credit: (float) $entry->credit,
                particulars: (string) ($entry->particulars ?? ''),
            ),
            'tally_voucher_type' => $entry->tally_voucher_type ?: $entry->voucher_type,
            'tally_voucher_no' => $entry->tally_voucher_no ?: ($voucherNo !== '' ? $voucherNo : null),
            'tally_entry_date' => $entry->tally_entry_date?->toDateString()
                ?: $entry->entry_date?->toDateString(),
            'tally_reconciled_at' => null,
        ]);
        $entry->save();
    }

    public function restoreSalesOrderEntry(DealerTallyEntry $entry): void
    {
        if ($entry->source !== DealerTallyEntry::SOURCE_SALES_ORDER || ! $this->isReconciled($entry)) {
            return;
        }

        $order = $entry->source_id !== null
            ? Order::query()->withTrashed()->find($entry->source_id)
            : null;
        $date = $order?->dealerLedgerEntryDate()
            ?? $entry->entry_date?->toDateString();

        $entry->fill([
            'entry_date' => $date,
            'particulars' => 'Sales Order',
            'voucher_type' => 'Sales',
            'voucher_no' => $order?->order_no ?: $entry->voucher_no,
            'import_id' => null,
            'tally_voucher_type' => null,
            'tally_voucher_no' => null,
            'tally_entry_date' => null,
            'tally_reconciled_at' => null,
        ]);
        $entry->save();
    }

    public function reconcileExistingDuplicates(?Dealer $dealer = null): int
    {
        $dealerIds = $dealer !== null
            ? collect([(int) $dealer->id])
            : DealerTallyEntry::query()
                ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
                ->distinct()
                ->pluck('dealer_id');

        $reconciled = 0;
        foreach ($dealerIds as $dealerId) {
            $reconciled += $this->reconcileDealerDuplicates((int) $dealerId);
        }

        return $reconciled;
    }

    private function reconcileDealerDuplicates(int $dealerId): int
    {
        $orderEntries = DealerTallyEntry::query()
            ->where('dealer_id', $dealerId)
            ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
            ->whereNull('tally_reconciled_at')
            ->whereRaw('COALESCE(debit, 0) > 0')
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (DealerTallyEntry $entry): bool => $this->orderGrandTotalMatches($entry));

        $tallySales = DealerTallyEntry::query()
            ->where('dealer_id', $dealerId)
            ->where('source', TallyLedgerConfig::SOURCE)
            ->whereRaw('COALESCE(debit, 0) > 0')
            ->whereRaw('ABS(COALESCE(credit, 0)) < 0.005')
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (DealerTallyEntry $entry): bool => $this->isSalesDebitEntry($entry));

        $pairs = $this->safePairs($orderEntries->values(), $tallySales->values());
        $count = 0;

        foreach ($pairs as [$orderEntry, $tallyEntry]) {
            $this->reconcileSalesOrderWithTally($orderEntry, [
                'date' => $tallyEntry->entry_date?->toDateString(),
                'particulars' => $tallyEntry->particulars,
                'voucher_type' => $tallyEntry->voucher_type,
                'voucher_no' => $tallyEntry->voucher_no,
            ], $tallyEntry->import_id !== null ? (int) $tallyEntry->import_id : null);
            $tallyEntry->delete();
            $count++;
        }

        if ($count > 0) {
            Log::debug('tally_sales_order_duplicates_reconciled', [
                'dealer_id' => $dealerId,
                'reconciled' => $count,
            ]);
        }

        return $count;
    }

    /**
     * @param  Collection<int, DealerTallyEntry>  $orderEntries
     * @param  Collection<int, DealerTallyEntry>  $tallyEntries
     * @return list<array{0: DealerTallyEntry, 1: DealerTallyEntry}>
     */
    private function safePairs(Collection $orderEntries, Collection $tallyEntries): array
    {
        $pairs = [];
        $usedOrderIds = [];
        $usedTallyIds = [];
        $orderByAmount = $orderEntries->groupBy(fn (DealerTallyEntry $entry): string => number_format((float) $entry->debit, 2, '.', ''));
        $tallyByAmount = $tallyEntries->groupBy(fn (DealerTallyEntry $entry): string => number_format((float) $entry->debit, 2, '.', ''));

        foreach ($orderByAmount as $amount => $orders) {
            $tallyGroup = $tallyByAmount->get($amount, collect());
            if ($orders->isEmpty() || $tallyGroup->isEmpty()) {
                continue;
            }

            if ($orders->count() === $tallyGroup->count()) {
                $ranked = $this->rankedPairs($orders, $tallyGroup);
                foreach ($ranked as $candidate) {
                    $orderId = (int) $candidate['order']->id;
                    $tallyId = (int) $candidate['tally']->id;
                    if (in_array($orderId, $usedOrderIds, true) || in_array($tallyId, $usedTallyIds, true)) {
                        continue;
                    }

                    $pairs[] = [$candidate['order'], $candidate['tally']];
                    $usedOrderIds[] = $orderId;
                    $usedTallyIds[] = $tallyId;
                }

                continue;
            }

            $ranked = $this->rankedPairs($orders, $tallyGroup);

            foreach ($ranked as $candidate) {
                $orderId = (int) $candidate['order']->id;
                $tallyId = (int) $candidate['tally']->id;
                if (in_array($orderId, $usedOrderIds, true) || in_array($tallyId, $usedTallyIds, true)) {
                    continue;
                }

                $sameDiffForOrder = collect($ranked)->filter(
                    fn (array $row): bool => (int) $row['order']->id === $orderId
                        && $row['diff'] === $candidate['diff']
                        && ! in_array((int) $row['tally']->id, $usedTallyIds, true),
                );
                $sameDiffForTally = collect($ranked)->filter(
                    fn (array $row): bool => (int) $row['tally']->id === $tallyId
                        && $row['diff'] === $candidate['diff']
                        && ! in_array((int) $row['order']->id, $usedOrderIds, true),
                );

                if ($sameDiffForOrder->count() !== 1 || $sameDiffForTally->count() !== 1) {
                    continue;
                }

                $pairs[] = [$candidate['order'], $candidate['tally']];
                $usedOrderIds[] = $orderId;
                $usedTallyIds[] = $tallyId;
            }
        }

        return $pairs;
    }

    /**
     * @param  Collection<int, DealerTallyEntry>  $orders
     * @param  Collection<int, DealerTallyEntry>  $tallyGroup
     * @return list<array{order: DealerTallyEntry, tally: DealerTallyEntry, diff: int}>
     */
    private function rankedPairs(Collection $orders, Collection $tallyGroup): array
    {
        $ranked = [];
        foreach ($orders as $orderEntry) {
            foreach ($tallyGroup as $tallyEntry) {
                $ranked[] = [
                    'order' => $orderEntry,
                    'tally' => $tallyEntry,
                    'diff' => $this->dateDistance($orderEntry, $tallyEntry->entry_date?->toDateString() ?? ''),
                ];
            }
        }

        usort($ranked, fn (array $a, array $b): int => $a['diff'] <=> $b['diff']);

        return $ranked;
    }

    /**
     * @return Collection<int, DealerTallyEntry>
     */
    private function unreconciledSalesOrderEntries(int $dealerId, float $debit): Collection
    {
        return DealerTallyEntry::query()
            ->where('dealer_id', $dealerId)
            ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
            ->whereNull('tally_reconciled_at')
            ->whereRaw('ABS(COALESCE(debit, 0) - ?) < 0.005', [$debit])
            ->whereRaw('ABS(COALESCE(credit, 0)) < 0.005')
            ->whereNotNull('source_id')
            ->whereExists(function ($query) use ($debit): void {
                $query->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.id', 'dealer_tally_entries.source_id')
                    ->whereColumn('orders.dealer_id', 'dealer_tally_entries.dealer_id')
                    ->whereRaw('ABS(COALESCE(orders.grand_total, 0) - ?) < 0.005', [$debit]);
            })
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, DealerTallyEntry>
     */
    private function unreconciledTallySalesEntries(int $dealerId, float $debit): Collection
    {
        return DealerTallyEntry::query()
            ->where('dealer_id', $dealerId)
            ->where('source', TallyLedgerConfig::SOURCE)
            ->whereNull('tally_reconciled_at')
            ->whereRaw('ABS(COALESCE(debit, 0) - ?) < 0.005', [$debit])
            ->whereRaw('ABS(COALESCE(credit, 0)) < 0.005')
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (DealerTallyEntry $entry): bool => $this->isSalesDebitEntry($entry))
            ->values();
    }

    /**
     * @param  Collection<int, DealerTallyEntry>  $candidates
     */
    private function uniquelyClosest(Collection $candidates, string $targetDate): ?DealerTallyEntry
    {
        $ranked = $candidates->map(fn (DealerTallyEntry $entry): array => [
            'entry' => $entry,
            'diff' => $this->dateDistance($entry, $targetDate),
        ]);
        $min = $ranked->min('diff');
        $closest = $ranked->filter(fn (array $row): bool => $row['diff'] === $min);

        if ($closest->count() !== 1) {
            return null;
        }

        return $closest->first()['entry'] ?? null;
    }

    private function dateDistance(DealerTallyEntry $entry, string $targetDate): int
    {
        $left = $entry->entry_date?->toDateString();
        if ($left === null || $targetDate === '') {
            return PHP_INT_MAX;
        }

        return (int) abs(Carbon::parse($left)->diffInDays(Carbon::parse($targetDate)));
    }

    private function orderGrandTotalMatches(DealerTallyEntry $entry): bool
    {
        if ($entry->source_id === null) {
            return false;
        }

        $order = Order::query()->find($entry->source_id);

        return $order !== null
            && (int) $order->dealer_id === (int) $entry->dealer_id
            && abs(round((float) $order->grand_total, 2) - round((float) $entry->debit, 2)) < 0.005;
    }

    private function isSalesDebit(float $debit, float $credit, string $voucherType, string $particulars): bool
    {
        if (round($debit, 2) <= 0.0 || round($credit, 2) > 0.0) {
            return false;
        }

        $type = strtolower(trim($voucherType));
        $text = strtolower(trim($particulars));

        if (preg_match('/credit\s*note|sales?\s*return|receipt|journal|payment|contra/i', $type) === 1) {
            return false;
        }

        if ($type !== '' && preg_match('/sales/i', $type) === 1) {
            return true;
        }

        return $type === ''
            && preg_match('/\bsales?\b/i', $text) === 1
            && preg_match('/return|credit\s*note/i', $text) !== 1;
    }

    private function normalizeVoucherNo(string $voucherNo): string
    {
        return Str::upper((string) preg_replace('/\s+/', '', $voucherNo));
    }
}
