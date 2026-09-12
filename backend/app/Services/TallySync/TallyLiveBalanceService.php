<?php

namespace App\Services\TallySync;

use App\Models\Dealer;
use App\Models\DealerTallyLedger;
use App\Models\TallyLiveSyncState;
use App\Services\TallyLedger\DealerTallyBalance;
use App\Services\TallyLedger\TallyDealerLedgerService;
use App\Services\TallyLedger\TallyLedgerConfig;
use App\Support\IndianCurrency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class TallyLiveBalanceService
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_MISMATCH = 'mismatch';

    public const STATUS_OFFLINE = 'offline';

    public const STATUS_NOT_SYNCED = 'not_synced';

    /**
     * @return array{force_sync: bool, offline_after_seconds: int, journal_from_date: string}
     */
    public function connectorPoll(): array
    {
        $state = TallyLiveSyncState::current();

        return [
            'force_sync' => $state->sync_requested_at !== null
                && ($state->last_balance_sync_at === null
                    || $state->sync_requested_at->gt($state->last_balance_sync_at)),
            'offline_after_seconds' => max(30, (int) config('tally.live_balance.offline_after_seconds', 120)),
            'journal_from_date' => TallyLedgerConfig::FINANCIAL_START_DATE,
        ];
    }

    public function requestSync(): TallyLiveSyncState
    {
        $state = TallyLiveSyncState::current();
        $state->fill(['sync_requested_at' => Carbon::now('Asia/Kolkata')]);
        $state->save();

        return $state->fresh() ?? $state;
    }

    /**
     * Store live Tally closing balances. Never writes dealer_tally_entries.
     *
     * @param  list<array{tally_ledger_name?: mixed, closing_balance?: mixed, closing_balance_type?: mixed}>  $balances
     * @return array{matched: int, unmatched: int, ambiguous: int, tally_online: bool}
     */
    public function ingest(?string $connectorId, bool $tallyOnline, array $balances): array
    {
        $now = Carbon::now('Asia/Kolkata');
        $matched = 0;
        $unmatched = 0;
        $ambiguous = 0;

        DB::transaction(function () use ($connectorId, $tallyOnline, $balances, $now, &$matched, &$unmatched, &$ambiguous): void {
            $state = TallyLiveSyncState::query()->lockForUpdate()->orderBy('id')->first()
                ?? TallyLiveSyncState::current();

            $state->fill([
                'connector_id' => filled($connectorId) ? mb_substr($connectorId, 0, 100) : $state->connector_id,
                'tally_online' => $tallyOnline,
                'last_seen_at' => $now,
            ]);

            if ($tallyOnline) {
                $state->last_tally_online_at = $now;
                $state->last_balance_sync_at = $now;
                $state->sync_requested_at = null;
                $mappings = app(TallyDealerMappingService::class);
                $mappings->upsertConnectorLedgers($balances, $now);
                $guidToDealer = $mappings->guidToDealerId();
                $guidMappedDealers = $mappings->guidMappedDealerIds();
                $dealersByName = $this->dealersByNormalizedName();
                $lookup = $this->uniqueDealerLookup($dealersByName, $guidMappedDealers);
                $existingLiveLookup = $this->existingLiveNameLookup($guidMappedDealers);
                $tallyNameCounts = $this->tallyNormalizedNameCounts($balances);
                $tallyGuidCounts = $this->tallyGuidCounts($balances);
                $matchedDealerIds = [];
                $seenTallyKeys = [];

                Log::info('Live Tally ingest received', [
                    'payload_ledgers' => count($balances),
                    'unique_erp_names' => count($lookup),
                    'guid_mappings' => $guidToDealer->count(),
                    'erp_dealers' => array_sum(array_map('count', $dealersByName)),
                ]);

                foreach ($balances as $row) {
                    $name = (string) ($row['tally_ledger_name'] ?? '');
                    if (trim($name) === '') {
                        continue;
                    }

                    $interpreted = TallyClosingBalanceInterpreter::interpret($row);
                    $type = $interpreted['type'];
                    $amount = $interpreted['amount'];
                    $guid = TallyDealerMappingService::normalizeGuid($row['tally_ledger_guid'] ?? '');
                    $normalized = TallyLiveLedgerName::normalize($name);
                    $displayName = TallyLiveLedgerName::canonical($name);
                    if ($normalized === '') {
                        $unmatched++;
                        $this->logMappingRejected($name, $dealersByName, 'normalized_name_empty', $normalized, $tallyNameCounts);

                        continue;
                    }

                    $dealerId = null;
                    $via = null;
                    if ($guid !== '' && ($tallyGuidCounts[$guid] ?? 0) > 1) {
                        $ambiguous++;
                        $unmatched++;
                        $this->logMappingRejected($name, $dealersByName, 'duplicate_tally_ledger_guid', $normalized, $tallyNameCounts);

                        continue;
                    }

                    if ($guid !== '' && $guidToDealer->has($guid)) {
                        $dealerId = (int) $guidToDealer->get($guid);
                        $via = 'guid';
                    }

                    if ($dealerId === null) {
                        if (($tallyNameCounts[$normalized] ?? 0) > 1) {
                            $ambiguous++;
                            $unmatched++;
                            $this->logMappingRejected($name, $dealersByName, 'duplicate_tally_ledger_name', $normalized, $tallyNameCounts);

                            continue;
                        }

                        $dealerId = $lookup[$normalized] ?? $existingLiveLookup[$normalized] ?? null;
                        if ($dealerId !== null) {
                            if (isset($guidMappedDealers[$dealerId])) {
                                $dealerId = null;
                            } else {
                                $via = 'name';
                            }
                        }
                    }

                    if ($dealerId === null) {
                        $unmatched++;
                        $erpHits = $dealersByName[$normalized] ?? [];
                        $reason = $erpHits === []
                            ? 'no_erp_dealer_with_exact_normalized_name'
                            : 'duplicate_erp_dealer_name';
                        $this->logMappingRejected($name, $dealersByName, $reason, $normalized, $tallyNameCounts);

                        continue;
                    }

                    $fingerprint = ($guid !== '' ? $guid : $normalized)."\0".$amount."\0".$type;
                    if (isset($seenTallyKeys[$fingerprint])) {
                        continue;
                    }
                    $seenTallyKeys[$fingerprint] = true;

                    $account = DealerTallyLedger::query()->firstOrCreate(
                        ['dealer_id' => $dealerId],
                        [
                            'opening_balance' => 0,
                            'opening_balance_type' => DealerTallyBalance::DEBIT,
                            'opening_balance_explicit' => false,
                            'financial_start_date' => TallyLedgerConfig::FINANCIAL_START_DATE,
                        ],
                    );
                    $account->fill([
                        'live_closing_balance' => $amount,
                        'live_closing_balance_type' => $type,
                        'live_tally_ledger_name' => $displayName !== '' ? $displayName : $name,
                        'live_tally_ledger_guid' => $guid !== '' ? $guid : $account->live_tally_ledger_guid,
                        'live_synced_at' => $now,
                    ]);
                    $account->save();
                    $matched++;
                    $matchedDealerIds[$dealerId] = $normalized;
                    $dealer = Dealer::query()->find($dealerId);
                    if ($dealer !== null) {
                        if ($guid !== '' && $via !== null) {
                            $mappings->attachGuidIfMissing($dealer, $guid, $displayName !== '' ? $displayName : $name);
                        }
                        app(TallyOutboundEnqueueService::class)
                            ->requeueNotMappedReceivedCollectionsForDealer($dealer);
                    }
                    $reportedType = strtolower(trim((string) ($row['closing_balance_type'] ?? '')));
                    if ($amount > 0 && $reportedType !== '' && $reportedType !== $type) {
                        Log::info('Live Tally Dr/Cr reinterpreted from XML', [
                            'tally_ledger_name' => $name,
                            'raw' => $interpreted['raw'] !== '' ? $interpreted['raw'] : null,
                            'numeric' => $interpreted['numeric'],
                            'parsed_dr_cr' => $type,
                            'sent_to_erp' => $amount.' '.$type,
                            'connector_type' => $reportedType,
                        ]);
                    }
                }

                $this->logMissingTallyLedgers($dealersByName, $balances, $matchedDealerIds);
                $state->last_matched_count = $matched;
            }

            $state->save();
        });

        return [
            'matched' => $matched,
            'unmatched' => $unmatched,
            'ambiguous' => $ambiguous,
            'tally_online' => $tallyOnline,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public function verification(Dealer $dealer, float $erpSigned, array $summary = []): array
    {
        $account = $dealer->tallyLedger;
        $state = TallyLiveSyncState::current();
        $erpLabel = IndianCurrency::formatDrCr($erpSigned);
        $lastSynced = $account?->live_synced_at ?? $state->last_balance_sync_at;
        $lastSyncedLabel = $lastSynced
            ? Carbon::parse($lastSynced)->timezone('Asia/Kolkata')->format('d M Y • h:i A')
            : null;

        $liveAmount = $account?->live_closing_balance;
        $liveType = $account?->live_closing_balance_type;
        $hasLive = $liveAmount !== null && $liveType !== null;
        $liveSigned = $hasLive
            ? DealerTallyBalance::signed((float) $liveAmount, (string) $liveType)
            : null;

        if (! $state->tallyIsOnline()) {
            return $this->offlineVerification(
                $dealer,
                $erpLabel,
                $liveSigned,
                $lastSyncedLabel,
                $state,
                $account?->live_tally_ledger_name,
            );
        }

        if (! $hasLive) {
            return [
                'status' => self::STATUS_NOT_SYNCED,
                'status_label' => 'Live Tally not mapped',
                'status_short' => 'Not Mapped',
                'tally_online' => true,
                'balance_matched' => null,
                'live_tally_signed' => null,
                'live_tally_label' => null,
                'erp_outstanding_label' => $erpLabel,
                'erp_closing_label' => $erpLabel,
                'tally_closing_label' => null,
                'difference' => null,
                'difference_label' => '—',
                'last_synced_label' => $lastSyncedLabel,
                'live_tally_ledger_name' => $account?->live_tally_ledger_name,
                ...$this->mappingVerification($dealer),
            ];
        }

        $matched = DealerTallyBalance::matches(
            DealerTallyBalance::amountFromSigned($erpSigned),
            DealerTallyBalance::typeFromSigned($erpSigned),
            (float) $liveAmount,
            (string) $liveType,
        );
        $difference = round($erpSigned - $liveSigned, 2);

        return [
            'status' => $matched ? self::STATUS_MATCHED : self::STATUS_MISMATCH,
            'status_label' => $matched ? 'Live Tally Matched' : 'Live Tally Balance Mismatch',
            'status_short' => $matched ? 'Matched' : 'Mismatch',
            'tally_online' => true,
            'balance_matched' => $matched,
            'live_tally_signed' => $liveSigned,
            'live_tally_label' => IndianCurrency::formatDrCr($liveSigned),
            'erp_outstanding_label' => $erpLabel,
            'erp_closing_label' => $erpLabel,
            'tally_closing_label' => IndianCurrency::formatDrCr($liveSigned),
            'difference' => $difference,
            'difference_label' => $matched ? IndianCurrency::formatExact(0) : IndianCurrency::formatDrCr($difference),
            'last_synced_label' => $lastSyncedLabel,
            'live_tally_ledger_name' => $account?->live_tally_ledger_name,
            ...$this->mappingVerification($dealer),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offlineVerification(
        Dealer $dealer,
        string $erpLabel,
        ?float $liveSigned,
        ?string $lastSyncedLabel,
        TallyLiveSyncState $state,
        ?string $liveTallyLedgerName,
    ): array {
        $when = $lastSyncedLabel ?: ($state->last_seen_at
            ? Carbon::parse($state->last_seen_at)->timezone('Asia/Kolkata')->format('d M Y • h:i A')
            : null);
        $label = $when === null
            ? 'Tally Offline / Not synced yet'
            : 'Tally Offline / Last synced at '.$when;

        return [
            'status' => self::STATUS_OFFLINE,
            'status_label' => $label,
            'status_short' => 'Offline',
            'tally_online' => false,
            'balance_matched' => null,
            'live_tally_signed' => $liveSigned,
            'live_tally_label' => $liveSigned === null ? null : IndianCurrency::formatDrCr($liveSigned),
            'erp_outstanding_label' => $erpLabel,
            'erp_closing_label' => $erpLabel,
            'tally_closing_label' => $liveSigned === null ? null : IndianCurrency::formatDrCr($liveSigned),
            'difference' => null,
            'difference_label' => '—',
            'last_synced_label' => $lastSyncedLabel,
            'live_tally_ledger_name' => $liveTallyLedgerName,
            ...$this->mappingVerification($dealer),
        ];
    }

    /**
     * @return array{
     *     mapping_status: string,
     *     mapping_has_guid: bool,
     *     mapped_tally_ledger_name: ?string,
     *     tally_ledger_guid: ?string
     * }
     */
    private function mappingVerification(Dealer $dealer): array
    {
        $status = app(TallyDealerMappingService::class)->status($dealer);

        return [
            'mapping_status' => $status['status'],
            'mapping_has_guid' => $status['has_guid'],
            'mapped_tally_ledger_name' => $status['tally_ledger_name'],
            'tally_ledger_guid' => $status['tally_ledger_guid'],
        ];
    }

    /**
     * @return array<string, list<Dealer>>
     */
    private function dealersByNormalizedName(): array
    {
        $groups = [];

        foreach (Dealer::query()->get(['id', 'firm_name', 'status']) as $dealer) {
            $key = TallyLiveLedgerName::normalize((string) $dealer->firm_name);
            if ($key === '') {
                continue;
            }

            $groups[$key][] = $dealer;
        }

        return $groups;
    }

    /**
     * Unique ERP firm names keyed by the strict Live Tally normalizer.
     * Duplicate active firm names are omitted so they stay Not Mapped.
     *
     * @param  array<string, list<Dealer>>  $groups
     * @param  array<int, true>  $guidMappedDealers
     * @return array<string, int>
     */
    private function uniqueDealerLookup(array $groups, array $guidMappedDealers = []): array
    {
        $lookup = [];

        foreach ($groups as $key => $dealers) {
            $active = array_values(array_filter(
                $dealers,
                static fn (Dealer $dealer): bool => (bool) $dealer->status,
            ));
            $candidates = count($active) === 1 ? $active : (count($dealers) === 1 ? $dealers : $active);
            if (count($candidates) !== 1) {
                continue;
            }

            $dealerId = (int) $candidates[0]->id;
            if (isset($guidMappedDealers[$dealerId])) {
                continue;
            }

            $lookup[$key] = $dealerId;
        }

        return $lookup;
    }

    /**
     * Keep currently live-mapped dealers attached when firm-name lookup is unavailable.
     *
     * @param  array<int, true>  $guidMappedDealers
     * @return array<string, int>
     */
    private function existingLiveNameLookup(array $guidMappedDealers): array
    {
        $lookup = [];
        $counts = [];

        foreach (DealerTallyLedger::query()->whereNotNull('live_tally_ledger_name')->get(['dealer_id', 'live_tally_ledger_name']) as $account) {
            $dealerId = (int) $account->dealer_id;
            if (isset($guidMappedDealers[$dealerId])) {
                continue;
            }

            $key = TallyLiveLedgerName::normalize((string) $account->live_tally_ledger_name);
            if ($key === '') {
                continue;
            }

            $counts[$key] = ($counts[$key] ?? 0) + 1;
            if ($counts[$key] === 1) {
                $lookup[$key] = $dealerId;
            } else {
                unset($lookup[$key]);
            }
        }

        return $lookup;
    }

    /**
     * @param  list<array{tally_ledger_guid?: mixed}>  $balances
     * @return array<string, int>
     */
    private function tallyGuidCounts(array $balances): array
    {
        $counts = [];
        foreach ($balances as $row) {
            $guid = TallyDealerMappingService::normalizeGuid($row['tally_ledger_guid'] ?? '');
            if ($guid === '') {
                continue;
            }
            $counts[$guid] = ($counts[$guid] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Same normalized Tally name with the same amount counts as one ledger.
     * Different amounts for the same name stay ambiguous.
     *
     * @param  list<array{tally_ledger_name?: mixed}>  $balances
     * @return array<string, int>
     */
    private function tallyNormalizedNameCounts(array $balances): array
    {
        $fingerprints = [];

        foreach ($balances as $row) {
            $normalized = TallyLiveLedgerName::normalize((string) ($row['tally_ledger_name'] ?? ''));
            if ($normalized === '') {
                continue;
            }

            $interpreted = TallyClosingBalanceInterpreter::interpret($row);
            $fingerprints[$normalized][$interpreted['amount']."\0".$interpreted['type']] = true;
        }

        $counts = [];
        foreach ($fingerprints as $normalized => $set) {
            $counts[$normalized] = count($set);
        }

        return $counts;
    }

    /**
     * @param  array<string, list<Dealer>>  $dealersByName
     * @param  array<string, int>  $tallyNameCounts
     */
    private function logMappingRejected(
        string $tallyName,
        array $dealersByName,
        string $reason,
        string $normalized,
        array $tallyNameCounts,
    ): void {
        $erpHits = $dealersByName[$normalized] ?? [];
        $shouldDebug = TallyLiveLedgerName::isDebugName($tallyName)
            || $reason !== 'no_erp_dealer_with_exact_normalized_name';

        foreach ($erpHits as $dealer) {
            if (TallyLiveLedgerName::isDebugName((string) $dealer->firm_name)) {
                $shouldDebug = true;
                break;
            }
        }

        if (! $shouldDebug) {
            return;
        }

        $comparisons = [];
        foreach ($erpHits as $dealer) {
            $comparisons[] = [
                'dealer_id' => $dealer->id,
                'status' => (bool) $dealer->status,
                ...TallyLiveLedgerName::compare((string) $dealer->firm_name, $tallyName),
            ];
        }

        if ($comparisons === [] && TallyLiveLedgerName::isDebugName($tallyName)) {
            foreach ($dealersByName as $dealers) {
                foreach ($dealers as $dealer) {
                    if (! TallyLiveLedgerName::isDebugName((string) $dealer->firm_name)) {
                        continue;
                    }
                    $comparisons[] = [
                        'dealer_id' => $dealer->id,
                        'status' => (bool) $dealer->status,
                        ...TallyLiveLedgerName::compare((string) $dealer->firm_name, $tallyName),
                    ];
                }
            }
        }

        Log::info('Live Tally dealer mapping rejected', [
            'reason' => $reason,
            'exact_comparison_result' => $comparisons[0]['exact_match'] ?? false,
            'tally_name_count' => $tallyNameCounts[$normalized] ?? 0,
            'erp_dealers_with_same_normalized_name' => count($erpHits),
            'tally' => TallyLiveLedgerName::inspect($tallyName),
            'comparisons' => $comparisons,
        ]);
    }

    /**
     * @param  array<string, list<Dealer>>  $dealersByName
     * @param  list<array{tally_ledger_name?: mixed}>  $balances
     * @param  array<int, string>  $matchedDealerIds
     */
    private function logMissingTallyLedgers(array $dealersByName, array $balances, array $matchedDealerIds): void
    {
        $tallyKeys = [];
        foreach ($balances as $row) {
            $normalized = TallyLiveLedgerName::normalize((string) ($row['tally_ledger_name'] ?? ''));
            if ($normalized !== '') {
                $tallyKeys[$normalized] = (string) ($row['tally_ledger_name'] ?? '');
            }
        }

        foreach ($dealersByName as $normalized => $dealers) {
            foreach ($dealers as $dealer) {
                if (isset($matchedDealerIds[(int) $dealer->id])) {
                    continue;
                }
                if (! TallyLiveLedgerName::isDebugName((string) $dealer->firm_name)
                    && ! TallyLiveLedgerName::isDebugName($tallyKeys[$normalized] ?? '')) {
                    continue;
                }

                $tallyName = $tallyKeys[$normalized] ?? '';
                $reason = $tallyName === ''
                    ? 'tally_ledger_not_in_connector_payload'
                    : (isset($matchedDealerIds[(int) $dealer->id]) ? 'not_mapped' : 'exact_name_present_but_not_mapped');

                Log::info('Live Tally ERP dealer was not mapped', [
                    'reason' => $reason,
                    'payload_ledgers' => count($balances),
                    'tally_ledger_in_payload' => $tallyName !== '',
                    'dealer_id' => $dealer->id,
                    'dealer_status' => (bool) $dealer->status,
                    'erp' => TallyLiveLedgerName::inspect((string) $dealer->firm_name),
                    'tally' => $tallyName === '' ? null : TallyLiveLedgerName::inspect($tallyName),
                    'exact_comparison_result' => $tallyName !== ''
                        && TallyLiveLedgerName::compare((string) $dealer->firm_name, $tallyName)['exact_match'],
                ]);
            }
        }
    }

    /**
     * Outstanding-page Live Tally counts from stored connector snapshots.
     *
     * @return array{
     *     matched: int,
     *     mismatched: int,
     *     not_synced: int,
     *     last_synced_label: string,
     *     banner: 'matched'|'mismatch'|null,
     *     banner_label: string|null
     * }
     */
    public function outstandingReconciliation(?int $assignedEmployeeId = null): array
    {
        $table = (new Dealer)->getTable();
        $erp = TallyDealerLedgerService::signedCurrentOutstandingSql($table);
        $live = self::liveSignedSql($table);

        $row = Dealer::query()
            ->where('status', true)
            ->when(
                $assignedEmployeeId !== null,
                fn (Builder $query) => $query->where('assigned_employee_id', $assignedEmployeeId),
            )
            ->toBase()
            ->selectRaw("COALESCE(SUM(CASE WHEN ({$live}) IS NULL THEN 1 ELSE 0 END), 0) as not_synced")
            ->selectRaw("COALESCE(SUM(CASE WHEN ({$live}) IS NOT NULL AND ROUND(({$erp}) - ({$live}), 2) = 0 THEN 1 ELSE 0 END), 0) as matched")
            ->selectRaw("COALESCE(SUM(CASE WHEN ({$live}) IS NOT NULL AND ROUND(({$erp}) - ({$live}), 2) <> 0 THEN 1 ELSE 0 END), 0) as mismatched")
            ->first();

        $matched = (int) ($row->matched ?? 0);
        $mismatched = (int) ($row->mismatched ?? 0);
        $notSynced = (int) ($row->not_synced ?? 0);

        $banner = null;
        $bannerLabel = null;
        if ($mismatched > 0) {
            $banner = self::STATUS_MISMATCH;
            $bannerLabel = $mismatched === 1
                ? '1 Dealer Has Tally Balance Mismatch'
                : $mismatched.' Dealers Have Tally Balance Mismatch';
        } elseif ($matched > 0) {
            $banner = self::STATUS_MATCHED;
            $bannerLabel = 'All Live Tally Balances Matched';
        }

        return [
            'matched' => $matched,
            'mismatched' => $mismatched,
            'not_synced' => $notSynced,
            'last_synced_label' => $this->lastBalanceSyncLabel() ?? 'Not synced yet',
            'banner' => $banner,
            'banner_label' => $bannerLabel,
        ];
    }

    /**
     * @param  Builder<Dealer>  $query
     * @return Builder<Dealer>
     */
    public function scopeByOutstandingLiveStatus(Builder $query, string $status): Builder
    {
        $table = $query->getModel()->getTable();
        $erp = TallyDealerLedgerService::signedCurrentOutstandingSql($table);
        $live = self::liveSignedSql($table);

        return match ($status) {
            self::STATUS_NOT_SYNCED => $query->whereRaw("({$live}) IS NULL"),
            self::STATUS_MATCHED => $query->whereRaw("({$live}) IS NOT NULL AND ROUND(({$erp}) - ({$live}), 2) = 0"),
            self::STATUS_MISMATCH => $query->whereRaw("({$live}) IS NOT NULL AND ROUND(({$erp}) - ({$live}), 2) <> 0"),
            default => $query,
        };
    }

    /**
     * @return array{status: string, label: string, difference: float|null, difference_label: string|null}
     */
    public function outstandingRowStatus(Dealer $dealer, float $erpSigned): array
    {
        $account = $dealer->tallyLedger;
        $hasLive = $account !== null
            && $account->live_closing_balance !== null
            && $account->live_closing_balance_type !== null;

        if (! $hasLive) {
            return [
                'status' => self::STATUS_NOT_SYNCED,
                'label' => 'Not Synced',
                'difference' => null,
                'difference_label' => null,
            ];
        }

        $liveAmount = (float) $account->live_closing_balance;
        $liveType = (string) $account->live_closing_balance_type;
        $liveSigned = DealerTallyBalance::signed($liveAmount, $liveType);
        $matched = DealerTallyBalance::matches(
            DealerTallyBalance::amountFromSigned($erpSigned),
            DealerTallyBalance::typeFromSigned($erpSigned),
            $liveAmount,
            $liveType,
        );
        $difference = round($erpSigned - $liveSigned, 2);

        return [
            'status' => $matched ? self::STATUS_MATCHED : self::STATUS_MISMATCH,
            'label' => $matched ? 'Matched' : 'Mismatch',
            'difference' => $matched ? 0.0 : $difference,
            'difference_label' => $matched ? null : IndianCurrency::formatDrCr($difference),
        ];
    }

    public function lastBalanceSyncLabel(): ?string
    {
        $synced = TallyLiveSyncState::current()->last_balance_sync_at;
        if ($synced === null) {
            return null;
        }

        return Carbon::parse($synced)->timezone('Asia/Kolkata')->format('d M Y • h:i A');
    }

    public static function liveSignedSql(string $dealersTable = 'dealers'): string
    {
        $credit = DealerTallyBalance::CREDIT;

        return "(
            SELECT CASE
                WHEN dealer_tally_ledgers.live_closing_balance IS NULL
                  OR dealer_tally_ledgers.live_closing_balance_type IS NULL
                THEN NULL
                WHEN LOWER(dealer_tally_ledgers.live_closing_balance_type) = '{$credit}'
                THEN -ABS(dealer_tally_ledgers.live_closing_balance)
                ELSE ABS(dealer_tally_ledgers.live_closing_balance)
            END
            FROM dealer_tally_ledgers
            WHERE dealer_tally_ledgers.dealer_id = {$dealersTable}.id
        )";
    }
}
