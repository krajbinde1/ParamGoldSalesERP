<?php

namespace App\Services\TallySync;

use App\Models\DealerTallyEntry;
use App\Models\DealerTallyLedger;
use App\Services\TallyLedger\DealerTallyBalance;
use App\Services\TallyLedger\TallyLedgerConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TallyJournalVoucherSyncService
{
    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<string>  $seenVoucherGuids
     * @return array{
     *     tally_online: bool,
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     reversed: int,
     *     unmatched: int,
     *     skipped: int
     * }
     */
    public function ingest(
        ?string $connectorId,
        bool $tallyOnline,
        array $entries,
        bool $syncComplete = false,
        array $seenVoucherGuids = [],
    ): array {
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $reversed = 0;
        $unmatched = 0;
        $skipped = 0;

        DB::transaction(function () use (
            $tallyOnline,
            $entries,
            $syncComplete,
            $seenVoucherGuids,
            &$created,
            &$updated,
            &$unchanged,
            &$reversed,
            &$unmatched,
            &$skipped,
        ): void {
            if (! $tallyOnline) {
                return;
            }

            $mappings = app(TallyDealerMappingService::class);
            $presentKeys = [];

            foreach ($entries as $row) {
                $result = $this->upsertRow($mappings, $row, $presentKeys);
                match ($result) {
                    'created' => $created++,
                    'updated' => $updated++,
                    'unchanged' => $unchanged++,
                    'reversed' => $reversed++,
                    'unmatched' => $unmatched++,
                    default => $skipped++,
                };
            }

            if ($syncComplete) {
                $reversed += $this->reverseMissing($seenVoucherGuids, $presentKeys);
            }
        });

        return [
            'tally_online' => $tallyOnline,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'reversed' => $reversed,
            'unmatched' => $unmatched,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string, true>  $presentKeys
     */
    private function upsertRow(TallyDealerMappingService $mappings, array $row, array &$presentKeys): string
    {
        $voucherType = trim((string) ($row['voucher_type'] ?? 'Journal'));
        if (! DealerTallyEntry::isJournalVoucherType($voucherType)) {
            return 'skipped';
        }

        $guid = TallyDealerMappingService::normalizeGuid($row['voucher_guid'] ?? $row['tally_voucher_guid'] ?? '');
        $masterId = trim((string) ($row['master_id'] ?? $row['tally_master_id'] ?? ''));
        $entryIndex = max(0, (int) ($row['entry_index'] ?? 0));
        $ledgerName = trim((string) ($row['party_ledger_name'] ?? $row['ledger_name'] ?? ''));
        $ledgerGuid = TallyDealerMappingService::normalizeGuid($row['party_ledger_guid'] ?? $row['ledger_guid'] ?? '');
        $entryKey = $this->entryKey($entryIndex, $ledgerGuid, $ledgerName);
        $cancelled = $this->isCancelled($row);
        $date = $this->entryDate($row);
        if ($date === null || $date < TallyLedgerConfig::FINANCIAL_START_DATE) {
            return 'skipped';
        }

        $debit = round(abs((float) ($row['debit'] ?? 0)), 2);
        $credit = round(abs((float) ($row['credit'] ?? 0)), 2);
        if ($debit > 0 && $credit > 0) {
            return 'skipped';
        }
        if ($debit <= 0 && $credit <= 0 && ! $cancelled) {
            return 'skipped';
        }

        if ($guid !== '') {
            $presentKeys[$guid.'|'.$entryKey] = true;
        }

        $dealerId = $mappings->dealerIdForLedger($ledgerGuid !== '' ? $ledgerGuid : null, $ledgerName);
        if ($dealerId === null) {
            return 'unmatched';
        }

        $this->ensureLedgerAccount($dealerId);

        $existing = $this->findExisting($dealerId, $guid, $entryKey, $date, $row, $debit, $credit);
        if ($existing !== null && $this->isProtectedErpEntry($existing)) {
            return 'skipped';
        }

        if ($cancelled) {
            if ($existing !== null) {
                $existing->delete();

                return 'reversed';
            }

            return 'unchanged';
        }

        $voucherNo = trim((string) ($row['voucher_no'] ?? $row['voucher_number'] ?? ''));
        $narration = trim((string) ($row['narration'] ?? ''));
        $particulars = $narration !== '' ? $narration : DealerTallyEntry::SOURCE_LABEL_TALLY_JOURNAL;
        $fingerprint = $guid !== ''
            ? DealerTallyEntry::makeJournalFingerprint($guid, $entryKey)
            : DealerTallyEntry::makeFingerprint(
                $dealerId,
                $date,
                'Journal',
                $voucherNo,
                $debit,
                $credit,
                $particulars,
            );

        $payload = [
            'dealer_id' => $dealerId,
            'entry_date' => $date,
            'particulars' => $particulars,
            'voucher_type' => 'Journal',
            'voucher_no' => $voucherNo !== '' ? $voucherNo : null,
            'tally_voucher_type' => $voucherType !== '' ? $voucherType : 'Journal',
            'tally_voucher_no' => $voucherNo !== '' ? $voucherNo : null,
            'tally_entry_date' => $date,
            'tally_voucher_guid' => $guid !== '' ? $guid : null,
            'tally_master_id' => $masterId !== '' ? $masterId : null,
            'tally_entry_key' => $entryKey,
            'debit' => $debit,
            'credit' => $credit,
            'source' => DealerTallyEntry::SOURCE_TALLY_JOURNAL,
            'fingerprint' => $fingerprint,
        ];

        if ($existing === null) {
            try {
                DealerTallyEntry::query()->create($payload);
            } catch (UniqueConstraintViolationException) {
                $collision = DealerTallyEntry::query()->where('fingerprint', $fingerprint)->first();
                if ($collision === null || $this->isProtectedErpEntry($collision)) {
                    return 'skipped';
                }

                return 'unchanged';
            }

            return 'created';
        }

        $changed = $this->hasChanged($existing, $payload);
        $existing->fill($payload);
        if ($changed) {
            $existing->save();

            return 'updated';
        }

        if ($existing->isDirty()) {
            $existing->save();
        }

        return 'unchanged';
    }

    /**
     * @param  list<string>  $seenVoucherGuids
     * @param  array<string, true>  $presentKeys
     */
    private function reverseMissing(array $seenVoucherGuids, array $presentKeys): int
    {
        if ($presentKeys === [] && $seenVoucherGuids === []) {
            return DealerTallyEntry::query()
                ->where('source', DealerTallyEntry::SOURCE_TALLY_JOURNAL)
                ->delete();
        }

        $reversed = 0;
        DealerTallyEntry::query()
            ->where('source', DealerTallyEntry::SOURCE_TALLY_JOURNAL)
            ->whereNotNull('tally_voucher_guid')
            ->where('tally_voucher_guid', '!=', '')
            ->orderBy('id')
            ->each(function (DealerTallyEntry $entry) use ($presentKeys, &$reversed): void {
                if ($this->isProtectedErpEntry($entry)) {
                    return;
                }

                $guid = TallyDealerMappingService::normalizeGuid($entry->tally_voucher_guid);
                $key = $guid.'|'.(string) $entry->tally_entry_key;
                if (isset($presentKeys[$key])) {
                    return;
                }

                $entry->delete();
                $reversed++;
            });

        return $reversed;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function findExisting(
        int $dealerId,
        string $guid,
        string $entryKey,
        string $date,
        array $row,
        float $debit,
        float $credit,
    ): ?DealerTallyEntry {
        if ($guid !== '') {
            $byIdentity = DealerTallyEntry::query()
                ->where('tally_voucher_guid', $guid)
                ->where('tally_entry_key', $entryKey)
                ->first();
            if ($byIdentity !== null) {
                return $byIdentity;
            }
        }

        $voucherNo = trim((string) ($row['voucher_no'] ?? $row['voucher_number'] ?? ''));
        $normalizedNo = Str::upper((string) preg_replace('/\s+/', '', $voucherNo));

        $query = DealerTallyEntry::query()
            ->where('dealer_id', $dealerId)
            ->whereDate('entry_date', $date)
            ->where(function ($inner): void {
                $inner->where('source', DealerTallyEntry::SOURCE_TALLY_JOURNAL)
                    ->orWhere(function ($journal): void {
                        $journal->where('source', DealerTallyEntry::SOURCE_TALLY_IMPORT)
                            ->where(function ($type): void {
                                $type->whereRaw('LOWER(COALESCE(voucher_type, ?)) = ?', ['', 'journal'])
                                    ->orWhereRaw('LOWER(COALESCE(voucher_type, ?)) LIKE ?', ['', 'journal %']);
                            });
                    });
            });

        if ($normalizedNo !== '') {
            $query->where(function ($inner) use ($normalizedNo): void {
                $inner->whereRaw("UPPER(REPLACE(COALESCE(voucher_no, ''), ' ', '')) = ?", [$normalizedNo])
                    ->orWhereRaw("UPPER(REPLACE(COALESCE(tally_voucher_no, ''), ' ', '')) = ?", [$normalizedNo]);
            });
        }

        $matches = $query->orderBy('id')->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }

        $sameAmount = $matches->first(function (DealerTallyEntry $entry) use ($debit, $credit): bool {
            return abs((float) $entry->debit - $debit) < 0.005
                && abs((float) $entry->credit - $credit) < 0.005;
        });

        return $sameAmount;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasChanged(DealerTallyEntry $existing, array $payload): bool
    {
        return $existing->entry_date?->toDateString() !== $payload['entry_date']
            || (string) $existing->particulars !== (string) $payload['particulars']
            || (string) $existing->voucher_no !== (string) ($payload['voucher_no'] ?? '')
            || abs((float) $existing->debit - (float) $payload['debit']) >= 0.005
            || abs((float) $existing->credit - (float) $payload['credit']) >= 0.005;
    }

    private function isProtectedErpEntry(DealerTallyEntry $entry): bool
    {
        return in_array($entry->source, [
            DealerTallyEntry::SOURCE_SALES_ORDER,
            DealerTallyEntry::SOURCE_COLLECTION,
        ], true);
    }

    private function ensureLedgerAccount(int $dealerId): void
    {
        DealerTallyLedger::query()->firstOrCreate(
            ['dealer_id' => $dealerId],
            [
                'opening_balance' => 0,
                'opening_balance_type' => DealerTallyBalance::DEBIT,
                'opening_balance_explicit' => false,
                'financial_start_date' => TallyLedgerConfig::FINANCIAL_START_DATE,
            ],
        );
    }

    private function entryKey(int $entryIndex, string $ledgerGuid, string $ledgerName): string
    {
        $identity = $ledgerGuid !== ''
            ? $ledgerGuid
            : TallyLiveLedgerName::normalize($ledgerName);

        return substr($entryIndex.'|'.$identity, 0, 80);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isCancelled(array $row): bool
    {
        $value = $row['cancelled'] ?? $row['is_cancelled'] ?? false;
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y'], true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function entryDate(array $row): ?string
    {
        $raw = trim((string) ($row['date'] ?? $row['voucher_date'] ?? ''));
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $raw;
        }
        if (preg_match('/^\d{8}$/', $raw) === 1) {
            return substr($raw, 0, 4).'-'.substr($raw, 4, 2).'-'.substr($raw, 6, 2);
        }

        try {
            return Carbon::parse($raw)->timezone('Asia/Kolkata')->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
