<?php

namespace App\Services\Dealers;

use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Models\Order;
use App\Models\TallyOutboundVoucher;
use App\Services\TallyLedger\TallyLedgerConfig;
use App\Services\TallySync\TallyDealerMappingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class DealerSalesLedgerReconciler
{
    public const DATE_WINDOW_DAYS = 14;

    public const MATCH_ERP_REFERENCE = 'erp_reference';

    public const MATCH_GUID = 'tally_guid';

    public const MATCH_BILL_REFERENCE = 'bill_reference';

    public const MATCH_UNIQUE_WINDOW = 'unique_window';

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
     * Exact Tally duplicate: fingerprint, GUID, or same dealer/voucher/amount
     * (date may differ after Tally numbering).
     *
     * @param  array<string, mixed>  $transaction
     */
    public function tallyDuplicateExists(int $dealerId, array $transaction, string $fingerprint): bool
    {
        if (DealerTallyEntry::query()->where('fingerprint', $fingerprint)->exists()) {
            return true;
        }

        $guid = $this->transactionGuid($transaction);
        if ($guid !== '' && $this->findByTallyGuid($guid) !== null) {
            return true;
        }

        $date = Carbon::parse((string) $transaction['date'])->timezone('Asia/Kolkata')->toDateString();
        $debit = round((float) $transaction['debit'], 2);
        $credit = round((float) $transaction['credit'], 2);
        $voucherNo = $this->normalizeVoucherNo((string) ($transaction['voucher_no'] ?? ''));

        $query = DealerTallyEntry::query()
            ->where('dealer_id', $dealerId)
            ->whereRaw('ABS(COALESCE(debit, 0) - ?) < 0.005', [$debit])
            ->whereRaw('ABS(COALESCE(credit, 0) - ?) < 0.005', [$credit]);

        if ($voucherNo === '') {
            $query->whereDate('entry_date', $date)
                ->where(function ($empty): void {
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

    public function findByTallyGuid(string $guid): ?DealerTallyEntry
    {
        $guid = TallyDealerMappingService::normalizeGuid($guid);
        if ($guid === '') {
            return null;
        }

        return DealerTallyEntry::query()
            ->where('tally_voucher_guid', $guid)
            ->orderBy('id')
            ->first();
    }

    /**
     * Unique unreconciled ERP Sales Order debit for this dealer and Tally sales row.
     *
     * @param  array<string, mixed>  $transaction
     */
    public function findMatchingSalesOrderEntry(
        int $dealerId,
        float $debit,
        string $tallyDate,
        array $transaction = [],
    ): ?DealerTallyEntry {
        $debit = round($debit, 2);
        if ($debit <= 0.0) {
            return null;
        }

        $guid = $this->transactionGuid($transaction);
        if ($guid !== '') {
            $byGuid = $this->findByTallyGuid($guid);
            if ($byGuid !== null
                && (int) $byGuid->dealer_id === $dealerId
                && $byGuid->source === DealerTallyEntry::SOURCE_SALES_ORDER) {
                return $byGuid;
            }
        }

        $candidates = $this->unreconciledSalesOrderEntries($dealerId, $debit);
        if ($candidates->isEmpty()) {
            return null;
        }

        $identity = $candidates->filter(function (DealerTallyEntry $entry) use ($transaction): bool {
            return $this->identityMatchesIncomingTally($entry, $transaction);
        })->values();

        if ($identity->count() === 1) {
            return $identity->first();
        }

        if ($identity->count() > 1) {
            return $this->uniquelyClosest($identity, $tallyDate);
        }

        $inWindow = $candidates
            ->filter(function (DealerTallyEntry $entry) use ($tallyDate, $transaction): bool {
                $order = $this->orderForEntry($entry);

                return $order instanceof Order
                    && ! $this->incomingRefersToDifferentOrder($transaction, $order)
                    && $this->withinDateWindow($entry, $tallyDate);
            })
            ->values();

        if ($inWindow->count() === 1) {
            return $inWindow->first();
        }

        if ($inWindow->isEmpty()) {
            return null;
        }

        return $this->uniquelyClosest($inWindow, $tallyDate);
    }

    /**
     * Unreconciled Tally sales debit that belongs to this ERP order.
     * Same dealer + same debit amount is never enough on its own.
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

        $candidates = $this->unreconciledTallySalesEntries((int) $order->dealer_id, $debit);
        if ($candidates->isEmpty()) {
            return null;
        }

        $identity = $candidates
            ->filter(fn (DealerTallyEntry $entry): bool => $this->identityMatchesOrder($entry, $order))
            ->values();

        if ($identity->count() === 1) {
            return $identity->first();
        }

        $orderDate = $order->dealerLedgerEntryDate();

        if ($identity->count() > 1) {
            return $this->uniquelyClosest($identity, $orderDate);
        }

        $inWindow = $candidates
            ->filter(function (DealerTallyEntry $entry) use ($order, $orderDate): bool {
                return ! $this->refersToDifferentOrder($entry, $order)
                    && $this->withinDateWindow($entry, $orderDate);
            })
            ->values();

        if ($inWindow->count() === 1) {
            return $inWindow->first();
        }

        if ($inWindow->isEmpty()) {
            return null;
        }

        return $this->uniquelyClosest($inWindow, $orderDate);
    }

    private function uniqueOwnerOrderForEntry(DealerTallyEntry $entry, int $exceptOrderId): ?Order
    {
        $debit = round((float) $entry->debit, 2);
        if ($debit <= 0.0 || abs((float) $entry->credit) >= 0.005) {
            return null;
        }

        $statusList = implode(',', array_fill(0, count(Order::billedReceivableStatuses()), '?'));
        $matches = Order::query()
            ->where('dealer_id', $entry->dealer_id)
            ->whereRaw(
                'LOWER(TRIM(status)) in ('.$statusList.')',
                Order::billedReceivableStatuses(),
            )
            ->whereKeyNot($exceptOrderId)
            ->whereRaw('ABS(COALESCE(grand_total, 0) - ?) < 0.005', [$debit])
            ->get();

        if ($matches->count() !== 1) {
            return null;
        }

        $owner = $matches->first();

        return $owner instanceof Order && $this->identityMatchesOrder($entry, $owner)
            ? $owner
            : null;
    }

    private function amountsMatch(DealerTallyEntry $entry, float $debit, float $credit): bool
    {
        return abs(round((float) $entry->debit, 2) - round($debit, 2)) < 0.005
            && abs(round((float) $entry->credit, 2) - round($credit, 2)) < 0.005;
    }

    /**
     * Voucher / particulars name a different sales order (PG-0015) than $order (PG-0001).
     * Full ERP numbers like PG-20260831-0001 must not be split into a fake PG-20260831 token.
     */
    public function refersToDifferentOrder(DealerTallyEntry $entry, Order $order): bool
    {
        return $this->haystackRefersToDifferentOrder($this->entryHaystack($entry), $order);
    }

    /**
     * True when voucher / particulars name this ERP sales order
     * (PG-20260831-0001, short PG-0001, or ERP-SO-{id}). Same dealer + same debit is not enough.
     */
    public function refersToThisOrder(DealerTallyEntry $entry, Order $order): bool
    {
        if ($this->refersToDifferentOrder($entry, $order)) {
            return false;
        }

        return $this->haystackRefersToThisOrder($this->entryHaystack($entry), $order);
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
        $guid = $this->transactionGuid($transaction);
        $masterId = trim((string) ($transaction['tally_master_id'] ?? $transaction['master_id'] ?? ''));
        $erpReference = filled($salesOrderEntry->erp_reference)
            ? (string) $salesOrderEntry->erp_reference
            : ($salesOrderEntry->source_id !== null
                ? DealerTallyEntry::salesErpReference((int) $salesOrderEntry->source_id)
                : null);

        $salesOrderEntry->fill([
            'entry_date' => $tallyDate,
            'particulars' => $particulars !== '' ? $particulars : $salesOrderEntry->particulars,
            'voucher_type' => $voucherType !== '' ? $voucherType : $salesOrderEntry->voucher_type,
            'voucher_no' => $voucherNo !== '' ? $voucherNo : $salesOrderEntry->voucher_no,
            'import_id' => $importId ?? $salesOrderEntry->import_id,
            'tally_voucher_type' => $voucherType !== '' ? $voucherType : $salesOrderEntry->tally_voucher_type,
            'tally_voucher_no' => $voucherNo !== '' ? $voucherNo : $salesOrderEntry->tally_voucher_no,
            'tally_entry_date' => $tallyDate,
            'tally_reconciled_at' => Carbon::now('Asia/Kolkata'),
            'tally_voucher_guid' => $guid !== '' ? $guid : $salesOrderEntry->tally_voucher_guid,
            'tally_master_id' => $masterId !== '' ? $masterId : $salesOrderEntry->tally_master_id,
            'tally_entry_key' => $guid !== ''
                ? DealerTallyEntry::SALES_ENTRY_KEY
                : $salesOrderEntry->tally_entry_key,
            'erp_reference' => $erpReference,
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

        $guid = TallyDealerMappingService::normalizeGuid((string) ($tallyEntry->tally_voucher_guid ?? ''));

        $tallyEntry->fill([
            'source' => DealerTallyEntry::SOURCE_SALES_ORDER,
            'source_id' => (int) $order->id,
            'erp_reference' => DealerTallyEntry::salesErpReference((int) $order->id),
            'fingerprint' => DealerTallyEntry::makeSourceFingerprint(
                DealerTallyEntry::SOURCE_SALES_ORDER,
                (int) $order->id,
            ),
            'tally_voucher_type' => $tallyEntry->tally_voucher_type ?: $tallyEntry->voucher_type,
            'tally_voucher_no' => $tallyEntry->tally_voucher_no ?: $tallyEntry->voucher_no,
            'tally_entry_date' => $tallyEntry->tally_entry_date?->toDateString()
                ?: $tallyEntry->entry_date?->toDateString(),
            'tally_voucher_guid' => $guid !== '' ? $guid : $tallyEntry->tally_voucher_guid,
            'tally_entry_key' => $guid !== ''
                ? DealerTallyEntry::SALES_ENTRY_KEY
                : $tallyEntry->tally_entry_key,
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
                )
                || $entry->erp_reference === DealerTallyEntry::salesErpReference((int) $order->id));
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
            || $entry->fingerprint === $fingerprint
            || $entry->erp_reference === DealerTallyEntry::salesErpReference((int) $order->id);

        if (! $pointsAtOrder) {
            return false;
        }

        if ((int) $entry->dealer_id !== (int) $order->dealer_id) {
            return true;
        }

        if ($this->refersToDifferentOrder($entry, $order)) {
            return true;
        }

        if ($this->amountsMatch($entry, round((float) $order->grand_total, 2), 0.0)) {
            if ($this->isTallyIdentity($entry)
                && ! $this->refersToThisOrder($entry, $order)
                && ! $this->orderOwnsTallyBill($order, $entry)
                && $this->dateDistance($entry, $order->dealerLedgerEntryDate()) > self::DATE_WINDOW_DAYS) {
                return true;
            }

            return false;
        }

        // Amount differs: never overwrite a Tally bill or a voucher that is not this order.
        // Unreconciled ERP-only rows (grand total edited) still update in place.
        if ($this->isReconciled($entry)
            || $entry->import_id !== null
            || $entry->source === TallyLedgerConfig::SOURCE) {
            return true;
        }

        $voucher = strtoupper(trim((string) ($entry->voucher_no ?? '')));
        if ($voucher === '') {
            return false;
        }

        $orderNo = strtoupper((string) $order->order_no);
        $short = strtoupper((string) $order->shortOrderNo());

        return $voucher !== $orderNo && $voucher !== $short;
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
        $claimedReference = DealerTallyEntry::salesErpReference((int) $claimedBy->id);

        if ((int) $entry->source_id !== (int) $claimedBy->id
            && $entry->fingerprint !== $claimedFingerprint
            && $entry->erp_reference !== $claimedReference) {
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
                        ->orWhere('erp_reference', DealerTallyEntry::salesErpReference((int) $owner->id))
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
                    'erp_reference' => DealerTallyEntry::salesErpReference((int) $owner->id),
                    'fingerprint' => $ownerFingerprint,
                ]);
                $entry->save();

                return;
            }
        }

        $voucherNo = (string) ($entry->tally_voucher_no ?: $entry->voucher_no ?: '');
        $fingerprint = DealerTallyEntry::makeFingerprint(
            dealerId: (int) $entry->dealer_id,
            date: $entry->entry_date?->toDateString() ?? Carbon::now('Asia/Kolkata')->toDateString(),
            voucherType: (string) ($entry->tally_voucher_type ?: $entry->voucher_type ?: ''),
            voucherNo: $voucherNo,
            debit: (float) $entry->debit,
            credit: (float) $entry->credit,
            particulars: (string) ($entry->particulars ?? ''),
        );
        if (DealerTallyEntry::query()->whereKeyNot($entry->id)->where('fingerprint', $fingerprint)->exists()) {
            $fingerprint = hash('sha256', $fingerprint.'|released|'.$entry->id);
        }

        $entry->fill([
            'source' => TallyLedgerConfig::SOURCE,
            'source_id' => null,
            'erp_reference' => null,
            'fingerprint' => $fingerprint,
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
            'tally_voucher_guid' => null,
            'tally_master_id' => null,
            'tally_entry_key' => null,
            'erp_reference' => $order instanceof Order
                ? DealerTallyEntry::salesErpReference((int) $order->id)
                : $entry->erp_reference,
        ]);
        $entry->save();
    }

    /**
     * @return array{
     *     definite: list<array<string, mixed>>,
     *     ambiguous: list<array<string, mixed>>
     * }
     */
    public function classifyExistingDuplicates(?Dealer $dealer = null): array
    {
        $dealerIds = $dealer !== null
            ? collect([(int) $dealer->id])
            : DealerTallyEntry::query()
                ->whereIn('source', [
                    DealerTallyEntry::SOURCE_SALES_ORDER,
                    TallyLedgerConfig::SOURCE,
                ])
                ->distinct()
                ->pluck('dealer_id');

        $definite = [];
        $ambiguous = [];

        foreach ($dealerIds as $dealerId) {
            $classified = $this->classifyDealerDuplicates((int) $dealerId);
            $definite = array_merge($definite, $classified['definite']);
            $ambiguous = array_merge($ambiguous, $classified['ambiguous']);
        }

        return [
            'definite' => $definite,
            'ambiguous' => $ambiguous,
        ];
    }

    public function reconcileExistingDuplicates(?Dealer $dealer = null): int
    {
        $classified = $this->classifyExistingDuplicates($dealer);
        $count = 0;

        foreach ($classified['definite'] as $pair) {
            $orderEntry = $pair['order_entry'] ?? null;
            $tallyEntry = $pair['tally_entry'] ?? null;
            if (! $orderEntry instanceof DealerTallyEntry || ! $tallyEntry instanceof DealerTallyEntry) {
                continue;
            }
            if ($orderEntry->is($tallyEntry)) {
                continue;
            }

            $this->applyDefinitePair($orderEntry, $tallyEntry);
            $count++;
        }

        return $count;
    }

    public function stampOutboundSalesSync(TallyOutboundVoucher $voucher): void
    {
        if ($voucher->source_type !== TallyOutboundVoucher::SOURCE_SALES_ORDER) {
            return;
        }

        $entry = DealerTallyEntry::query()
            ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
            ->where(function ($query) use ($voucher): void {
                $query->where('source_id', (int) $voucher->source_id)
                    ->orWhere('erp_reference', DealerTallyEntry::salesErpReference((int) $voucher->source_id))
                    ->orWhere('fingerprint', DealerTallyEntry::makeSourceFingerprint(
                        DealerTallyEntry::SOURCE_SALES_ORDER,
                        (int) $voucher->source_id,
                    ));
            })
            ->orderBy('id')
            ->first();

        if ($entry === null) {
            return;
        }

        $voucherNo = trim((string) ($voucher->tally_voucher_no ?? ''));
        $masterId = trim((string) ($voucher->tally_master_id ?? ''));

        $entry->fill([
            'erp_reference' => filled($entry->erp_reference)
                ? $entry->erp_reference
                : DealerTallyEntry::salesErpReference((int) $voucher->source_id),
            'tally_voucher_no' => $voucherNo !== '' ? $voucherNo : $entry->tally_voucher_no,
            'tally_master_id' => $masterId !== '' ? $masterId : $entry->tally_master_id,
        ]);
        $entry->save();
    }

    /**
     * @return array{
     *     definite: list<array<string, mixed>>,
     *     ambiguous: list<array<string, mixed>>
     * }
     */
    private function classifyDealerDuplicates(int $dealerId): array
    {
        $dealer = Dealer::query()->find($dealerId);
        $dealerName = (string) ($dealer?->firm_name ?? ('Dealer #'.$dealerId));

        $orderEntries = DealerTallyEntry::query()
            ->where('dealer_id', $dealerId)
            ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
            ->whereRaw('COALESCE(debit, 0) > 0')
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (DealerTallyEntry $entry): bool => $this->orderGrandTotalMatches($entry))
            ->values();

        $tallySales = DealerTallyEntry::query()
            ->where('dealer_id', $dealerId)
            ->where('source', TallyLedgerConfig::SOURCE)
            ->whereRaw('COALESCE(debit, 0) > 0')
            ->whereRaw('ABS(COALESCE(credit, 0)) < 0.005')
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (DealerTallyEntry $entry): bool => $this->isSalesDebitEntry($entry))
            ->values();

        $usedOrderIds = [];
        $usedTallyIds = [];
        $definite = [];

        foreach ($orderEntries as $orderEntry) {
            $order = $this->orderForEntry($orderEntry);
            if (! $order instanceof Order) {
                continue;
            }

            foreach ($tallySales as $tallyEntry) {
                if (in_array((int) $tallyEntry->id, $usedTallyIds, true)
                    || in_array((int) $orderEntry->id, $usedOrderIds, true)) {
                    continue;
                }
                if (! $this->amountsMatch($tallyEntry, round((float) $orderEntry->debit, 2), 0.0)) {
                    continue;
                }

                $reason = $this->historicalIdentityReason($orderEntry, $tallyEntry, $order);
                if ($reason === null) {
                    continue;
                }

                $definite[] = $this->definiteRow(
                    $dealerId,
                    $dealerName,
                    $reason,
                    $orderEntry,
                    $tallyEntry,
                    $order,
                );
                $usedOrderIds[] = (int) $orderEntry->id;
                $usedTallyIds[] = (int) $tallyEntry->id;
            }
        }

        $leftoverOrders = $orderEntries->filter(
            fn (DealerTallyEntry $entry): bool => ! in_array((int) $entry->id, $usedOrderIds, true),
        );
        $leftoverTally = $tallySales->filter(
            fn (DealerTallyEntry $entry): bool => ! in_array((int) $entry->id, $usedTallyIds, true),
        );

        $orderByAmount = $leftoverOrders->groupBy(
            fn (DealerTallyEntry $entry): string => number_format((float) $entry->debit, 2, '.', ''),
        );
        $tallyByAmount = $leftoverTally->groupBy(
            fn (DealerTallyEntry $entry): string => number_format((float) $entry->debit, 2, '.', ''),
        );
        $ambiguous = [];

        foreach ($orderByAmount as $amount => $orders) {
            $tallyGroup = $tallyByAmount->get($amount, collect());
            if ($tallyGroup->isEmpty()) {
                continue;
            }

            $windowPairs = [];
            foreach ($orders as $orderEntry) {
                $order = $this->orderForEntry($orderEntry);
                if (! $order instanceof Order) {
                    continue;
                }
                foreach ($tallyGroup as $tallyEntry) {
                    if ($this->refersToDifferentOrder($tallyEntry, $order)) {
                        continue;
                    }
                    if (! $this->withinDateWindow($orderEntry, $tallyEntry->entry_date?->toDateString() ?? '')) {
                        continue;
                    }
                    $windowPairs[] = [$orderEntry, $tallyEntry, $order];
                }
            }

            if (count($orders) === 1 && count($tallyGroup) === 1) {
                $orderEntry = $orders->first();
                $tallyEntry = $tallyGroup->first();
                $order = $orderEntry instanceof DealerTallyEntry ? $this->orderForEntry($orderEntry) : null;
                if ($orderEntry instanceof DealerTallyEntry
                    && $tallyEntry instanceof DealerTallyEntry
                    && $order instanceof Order
                    && ! $this->refersToDifferentOrder($tallyEntry, $order)
                    && $this->withinDateWindow($orderEntry, $tallyEntry->entry_date?->toDateString() ?? '')) {
                    $definite[] = $this->definiteRow(
                        $dealerId,
                        $dealerName,
                        self::MATCH_UNIQUE_WINDOW,
                        $orderEntry,
                        $tallyEntry,
                        $order,
                    );
                    $usedOrderIds[] = (int) $orderEntry->id;
                    $usedTallyIds[] = (int) $tallyEntry->id;

                    continue;
                }

                $ambiguous[] = $this->ambiguousRow(
                    $dealerId,
                    $dealerName,
                    (float) $amount,
                    $this->withinDateWindow(
                        $orders->first(),
                        $tallyGroup->first()?->entry_date?->toDateString() ?? '',
                    ) ? 'same_amount_names_a_different_order' : 'same_amount_outside_date_window',
                    $orders,
                    $tallyGroup,
                );

                continue;
            }

            $ambiguous[] = $this->ambiguousRow(
                $dealerId,
                $dealerName,
                (float) $amount,
                count($windowPairs) > 0
                    ? 'multiple_same_amount_sales_in_date_window'
                    : 'multiple_same_amount_sales_without_unique_identity',
                $orders,
                $tallyGroup,
            );
        }

        return [
            'definite' => $definite,
            'ambiguous' => $ambiguous,
        ];
    }

    private function applyDefinitePair(DealerTallyEntry $orderEntry, DealerTallyEntry $tallyEntry): void
    {
        $this->reconcileSalesOrderWithTally($orderEntry, [
            'date' => $tallyEntry->entry_date?->toDateString(),
            'particulars' => $tallyEntry->particulars,
            'voucher_type' => $tallyEntry->voucher_type,
            'voucher_no' => $tallyEntry->voucher_no,
            'tally_voucher_guid' => $tallyEntry->tally_voucher_guid,
            'tally_master_id' => $tallyEntry->tally_master_id,
        ], $tallyEntry->import_id !== null ? (int) $tallyEntry->import_id : null);
        $tallyEntry->delete();

        Log::debug('tally_sales_order_duplicate_linked', [
            'dealer_id' => $orderEntry->dealer_id,
            'erp_entry_id' => $orderEntry->id,
            'tally_entry_id' => $tallyEntry->id,
            'erp_reference' => $orderEntry->erp_reference,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function definiteRow(
        int $dealerId,
        string $dealerName,
        string $reason,
        DealerTallyEntry $orderEntry,
        DealerTallyEntry $tallyEntry,
        Order $order,
    ): array {
        return [
            'dealer_id' => $dealerId,
            'dealer_name' => $dealerName,
            'reason' => $reason,
            'order_entry' => $orderEntry,
            'tally_entry' => $tallyEntry,
            'order' => $order,
            'erp_entry_id' => (int) $orderEntry->id,
            'erp_order_no' => (string) $order->order_no,
            'erp_reference' => (string) ($orderEntry->erp_reference ?: DealerTallyEntry::salesErpReference((int) $order->id)),
            'erp_date' => $orderEntry->entry_date?->toDateString(),
            'erp_debit' => round((float) $orderEntry->debit, 2),
            'tally_entry_id' => (int) $tallyEntry->id,
            'tally_voucher_no' => (string) ($tallyEntry->voucher_no ?: $tallyEntry->tally_voucher_no),
            'tally_guid' => (string) ($tallyEntry->tally_voucher_guid ?? ''),
            'tally_date' => $tallyEntry->entry_date?->toDateString(),
            'tally_debit' => round((float) $tallyEntry->debit, 2),
        ];
    }

    /**
     * @param  Collection<int, DealerTallyEntry>  $orders
     * @param  Collection<int, DealerTallyEntry>  $tallyGroup
     * @return array<string, mixed>
     */
    private function ambiguousRow(
        int $dealerId,
        string $dealerName,
        float $amount,
        string $reason,
        Collection $orders,
        Collection $tallyGroup,
    ): array {
        return [
            'dealer_id' => $dealerId,
            'dealer_name' => $dealerName,
            'reason' => $reason,
            'amount' => $amount,
            'erp_vouchers' => $orders
                ->map(fn (DealerTallyEntry $entry): string => (string) ($entry->voucher_no ?: '#'.$entry->id))
                ->implode(', '),
            'tally_vouchers' => $tallyGroup
                ->map(fn (DealerTallyEntry $entry): string => (string) ($entry->voucher_no ?: '#'.$entry->id))
                ->implode(', '),
            'erp_entry_ids' => $orders->pluck('id')->all(),
            'tally_entry_ids' => $tallyGroup->pluck('id')->all(),
        ];
    }

    private function historicalIdentityReason(
        DealerTallyEntry $orderEntry,
        DealerTallyEntry $tallyEntry,
        Order $order,
    ): ?string {
        $orderGuid = TallyDealerMappingService::normalizeGuid((string) ($orderEntry->tally_voucher_guid ?? ''));
        $tallyGuid = TallyDealerMappingService::normalizeGuid((string) ($tallyEntry->tally_voucher_guid ?? ''));
        if ($orderGuid !== '' && $orderGuid === $tallyGuid) {
            return self::MATCH_GUID;
        }

        if ($this->refersToDifferentOrder($tallyEntry, $order)) {
            return null;
        }

        if ($this->haystackRefersToThisOrder($this->entryHaystack($tallyEntry), $order)) {
            return self::MATCH_ERP_REFERENCE;
        }

        if ($this->incomingBillMatchesOrder(
            (string) ($tallyEntry->voucher_no ?: $tallyEntry->tally_voucher_no),
            $order,
            $orderEntry,
        )) {
            return self::MATCH_BILL_REFERENCE;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function identityMatchesIncomingTally(DealerTallyEntry $entry, array $transaction): bool
    {
        $order = $this->orderForEntry($entry);
        if (! $order instanceof Order) {
            return false;
        }

        if ($this->incomingRefersToDifferentOrder($transaction, $order)) {
            return false;
        }

        $guid = $this->transactionGuid($transaction);
        $entryGuid = TallyDealerMappingService::normalizeGuid((string) ($entry->tally_voucher_guid ?? ''));
        if ($guid !== '' && $guid === $entryGuid) {
            return true;
        }

        if ($this->haystackRefersToThisOrder($this->transactionHaystack($transaction), $order)) {
            return true;
        }

        return $this->incomingBillMatchesOrder(
            (string) ($transaction['voucher_no'] ?? ''),
            $order,
            $entry,
        );
    }

    private function identityMatchesOrder(DealerTallyEntry $entry, Order $order): bool
    {
        if ($this->refersToDifferentOrder($entry, $order)) {
            return false;
        }

        if ($this->refersToThisOrder($entry, $order)) {
            return true;
        }

        return $this->billReferenceMatchesOrder($entry, $order);
    }

    private function billReferenceMatchesOrder(DealerTallyEntry $entry, Order $order): bool
    {
        return $this->incomingBillMatchesOrder(
            (string) ($entry->voucher_no ?: $entry->tally_voucher_no),
            $order,
            $entry,
        );
    }

    private function orderOwnsTallyBill(Order $order, DealerTallyEntry $entry): bool
    {
        return $this->incomingBillMatchesOrder(
            (string) ($entry->voucher_no ?: $entry->tally_voucher_no),
            $order,
        );
    }

    private function incomingBillMatchesOrder(
        string $incomingVoucherNo,
        Order $order,
        ?DealerTallyEntry $erpEntry = null,
    ): bool {
        $incoming = $this->normalizeVoucherNo($incomingVoucherNo);
        if ($incoming === '') {
            return false;
        }

        $erpBill = $erpEntry !== null
            && $erpEntry->source === DealerTallyEntry::SOURCE_SALES_ORDER
            ? $this->normalizeVoucherNo((string) ($erpEntry->tally_voucher_no ?? ''))
            : '';

        $known = array_values(array_filter([
            $this->normalizeVoucherNo((string) $order->bill_number),
            $erpBill,
            $this->normalizeVoucherNo((string) $this->syncedTallyVoucherNo($order)),
        ]));

        return in_array($incoming, $known, true);
    }

    private function syncedTallyVoucherNo(Order $order): string
    {
        $voucher = TallyOutboundVoucher::query()
            ->where('source_type', TallyOutboundVoucher::SOURCE_SALES_ORDER)
            ->where('source_id', $order->id)
            ->where('status', TallyOutboundVoucher::STATUS_SYNCED)
            ->orderByDesc('id')
            ->first();

        return (string) ($voucher?->tally_voucher_no ?? '');
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

        $entry = $closest->first()['entry'] ?? null;
        if (! $entry instanceof DealerTallyEntry || $min > self::DATE_WINDOW_DAYS) {
            return null;
        }

        return $entry;
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

    private function dateDistance(DealerTallyEntry $entry, string $targetDate): int
    {
        $left = $entry->entry_date?->toDateString();
        if ($left === null || $targetDate === '') {
            return PHP_INT_MAX;
        }

        return (int) abs(Carbon::parse($left)->diffInDays(Carbon::parse($targetDate)));
    }

    private function withinDateWindow(DealerTallyEntry $entry, string $targetDate): bool
    {
        return $this->dateDistance($entry, $targetDate) <= self::DATE_WINDOW_DAYS;
    }

    private function orderGrandTotalMatches(DealerTallyEntry $entry): bool
    {
        $order = $this->orderForEntry($entry);

        return $order !== null
            && (int) $order->dealer_id === (int) $entry->dealer_id
            && abs(round((float) $order->grand_total, 2) - round((float) $entry->debit, 2)) < 0.005;
    }

    private function orderForEntry(DealerTallyEntry $entry): ?Order
    {
        if ($entry->source_id === null) {
            return null;
        }

        $order = Order::query()->find($entry->source_id);

        return $order instanceof Order ? $order : null;
    }

    private function isTallyIdentity(DealerTallyEntry $entry): bool
    {
        return $entry->source === TallyLedgerConfig::SOURCE
            || $entry->import_id !== null
            || filled($entry->tally_voucher_no)
            || $entry->tally_reconciled_at !== null
            || $entry->tally_entry_date !== null
            || filled($entry->tally_voucher_guid);
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

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function transactionGuid(array $transaction): string
    {
        return TallyDealerMappingService::normalizeGuid(
            $transaction['tally_voucher_guid'] ?? $transaction['voucher_guid'] ?? $transaction['guid'] ?? '',
        );
    }

    private function entryHaystack(DealerTallyEntry $entry): string
    {
        return strtoupper(trim(implode(' ', array_filter([
            (string) $entry->voucher_no,
            (string) $entry->tally_voucher_no,
            (string) $entry->particulars,
            (string) $entry->erp_reference,
        ]))));
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function transactionHaystack(array $transaction): string
    {
        return strtoupper(trim(implode(' ', array_filter([
            (string) ($transaction['voucher_no'] ?? ''),
            (string) ($transaction['tally_voucher_no'] ?? ''),
            (string) ($transaction['particulars'] ?? ''),
            (string) ($transaction['reference'] ?? ''),
            (string) ($transaction['erp_reference'] ?? ''),
        ]))));
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function incomingRefersToDifferentOrder(array $transaction, Order $order): bool
    {
        return $this->haystackRefersToDifferentOrder($this->transactionHaystack($transaction), $order);
    }

    private function haystackRefersToDifferentOrder(string $haystack, Order $order): bool
    {
        if ($haystack === '') {
            return false;
        }

        $full = strtoupper((string) $order->order_no);
        $ownShort = array_values(array_unique(array_filter([
            strtoupper((string) $order->shortOrderNo()),
            preg_match('/^PG-\d{8}-(\d+)$/', $full, $parts) === 1 ? 'PG-'.$parts[1] : null,
        ])));
        $ownFull = $full !== '' ? [$full] : [];

        if (preg_match_all('/\bPG-\d{8}-\d+\b/', $haystack, $fullMatches) > 0) {
            foreach ($fullMatches[0] as $token) {
                if (! in_array($token, $ownFull, true)) {
                    return true;
                }
            }
        }

        $withoutFull = preg_replace('/\bPG-\d{8}-\d+\b/', ' ', $haystack) ?? $haystack;
        if (preg_match_all('/\bPG-\d+\b/', $withoutFull, $shortMatches) === 0) {
            return false;
        }

        foreach ($shortMatches[0] as $token) {
            if (preg_match('/^PG-\d{8}$/', $token) === 1 || in_array($token, $ownShort, true) || $token === $full) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function haystackRefersToThisOrder(string $haystack, Order $order): bool
    {
        if ($this->haystackRefersToDifferentOrder($haystack, $order)) {
            return false;
        }

        $full = strtoupper(trim((string) $order->order_no));
        $short = strtoupper(trim((string) $order->shortOrderNo()));
        $erpReference = strtoupper(DealerTallyEntry::salesErpReference((int) $order->id));
        $upper = strtoupper($haystack);

        if ($erpReference !== '' && str_contains($upper, $erpReference)) {
            return true;
        }

        if ($full !== '' && str_contains($upper, $full)) {
            return true;
        }

        $normalizedFull = $this->normalizeVoucherNo($full);
        $normalizedHaystack = $this->normalizeVoucherNo($haystack);
        if ($normalizedFull !== '' && $normalizedHaystack === $normalizedFull) {
            return true;
        }

        return $short !== ''
            && $short !== $full
            && preg_match('/\b'.preg_quote($short, '/').'\b/', $upper) === 1;
    }
}
