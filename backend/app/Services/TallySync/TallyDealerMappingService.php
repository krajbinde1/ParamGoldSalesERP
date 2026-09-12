<?php

namespace App\Services\TallySync;

use App\Exceptions\TallyMappingException;
use App\Models\Dealer;
use App\Models\DealerTallyLedger;
use App\Models\TallyConnectorLedger;
use App\Models\TallyDealerMapping;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class TallyDealerMappingService
{
    public static function normalizeGuid(mixed $guid): string
    {
        $text = strtolower(trim((string) $guid));
        $text = str_replace(['{', '}'], '', $text);
        $text = preg_replace('/\s+/', '', $text) ?? $text;

        return $text;
    }

    public function mappingFor(Dealer $dealer): ?TallyDealerMapping
    {
        $rows = TallyDealerMapping::query()
            ->where('dealer_id', $dealer->id)
            ->orderByRaw('CASE WHEN tally_ledger_guid IS NULL OR tally_ledger_guid = ? THEN 1 ELSE 0 END', [''])
            ->orderBy('id')
            ->get();

        return $rows->first();
    }

    public function guidMappingFor(Dealer $dealer): ?TallyDealerMapping
    {
        $mapping = $this->mappingFor($dealer);

        return $mapping !== null && self::normalizeGuid($mapping->tally_ledger_guid) !== ''
            ? $mapping
            : null;
    }

    public function isGuidMapped(Dealer $dealer): bool
    {
        return $this->guidMappingFor($dealer) !== null;
    }

    /**
     * Ledger to use for Tally voucher posting. Never guesses a similar name.
     *
     * @return array{ledger: ?string, guid: ?string, error: ?string}
     */
    public function outboundLedger(Dealer $dealer): array
    {
        $rows = TallyDealerMapping::query()
            ->where('dealer_id', $dealer->id)
            ->orderBy('id')
            ->get();
        $guidRows = $rows->filter(
            fn (TallyDealerMapping $mapping): bool => self::normalizeGuid($mapping->tally_ledger_guid) !== '',
        );

        if ($guidRows->count() > 1) {
            return [
                'ledger' => null,
                'guid' => null,
                'error' => TallyOutboundEnqueueService::ERROR_MULTIPLE_MAPPINGS,
            ];
        }

        if ($guidRows->count() === 1) {
            $mapping = $guidRows->first();
            $name = trim((string) $mapping?->tally_ledger_name);
            if ($name === '') {
                return [
                    'ledger' => null,
                    'guid' => null,
                    'error' => TallyOutboundEnqueueService::ERROR_NO_MAPPING,
                ];
            }

            return [
                'ledger' => $name,
                'guid' => self::normalizeGuid($mapping?->tally_ledger_guid),
                'error' => null,
            ];
        }

        $named = $rows->filter(fn (TallyDealerMapping $mapping): bool => trim((string) $mapping->tally_ledger_name) !== '');
        if ($named->count() > 1) {
            return [
                'ledger' => null,
                'guid' => null,
                'error' => TallyOutboundEnqueueService::ERROR_MULTIPLE_MAPPINGS,
            ];
        }

        if ($named->count() === 1) {
            $mapping = $named->first();

            return [
                'ledger' => trim((string) $mapping?->tally_ledger_name),
                'guid' => null,
                'error' => null,
            ];
        }

        $dealer->loadMissing('tallyLedger');
        $rawLiveName = trim((string) ($dealer->tallyLedger?->live_tally_ledger_name ?? ''));
        $liveName = TallyLiveLedgerName::canonical($rawLiveName);
        $firmName = TallyLiveLedgerName::canonical((string) $dealer->firm_name);
        if ($liveName !== '' && $firmName !== '' && $liveName === $firmName) {
            return [
                'ledger' => $rawLiveName !== '' ? $rawLiveName : $liveName,
                'guid' => self::normalizeGuid($dealer->tallyLedger?->live_tally_ledger_guid) ?: null,
                'error' => null,
            ];
        }

        return [
            'ledger' => null,
            'guid' => null,
            'error' => TallyOutboundEnqueueService::ERROR_NO_MAPPING,
        ];
    }

    /**
     * @return array{
     *     mapped: bool,
     *     has_guid: bool,
     *     status: string,
     *     tally_ledger_guid: ?string,
     *     tally_ledger_name: ?string
     * }
     */
    public function status(Dealer $dealer): array
    {
        $mapping = $this->mappingFor($dealer);
        $guid = self::normalizeGuid($mapping?->tally_ledger_guid);
        $liveName = TallyLiveLedgerName::canonical((string) ($dealer->tallyLedger?->live_tally_ledger_name ?? ''));
        $mappedName = trim((string) ($mapping?->tally_ledger_name ?? ''));
        $hasGuid = $guid !== '';
        $hasLive = $dealer->tallyLedger?->live_synced_at !== null
            && $dealer->tallyLedger?->live_closing_balance_type !== null;
        $mapped = $hasGuid || $hasLive || $mappedName !== '';

        return [
            'mapped' => $mapped,
            'has_guid' => $hasGuid,
            'status' => $mapped ? 'Mapped' : 'Not Mapped',
            'tally_ledger_guid' => $hasGuid ? $guid : null,
            'tally_ledger_name' => $mappedName !== '' ? $mappedName : ($liveName !== '' ? $liveName : null),
        ];
    }

    /**
     * Dealer for a Tally ledger line. GUID mapping wins; otherwise unique exact name.
     * Never guesses a similar name or an ambiguous duplicate.
     */
    public function dealerIdForLedger(?string $guid, string $ledgerName): ?int
    {
        $guid = self::normalizeGuid($guid);
        if ($guid !== '') {
            $mappedId = $this->guidToDealerId()->get($guid);
            if ($mappedId !== null) {
                return (int) $mappedId;
            }
        }

        $normalizedLive = TallyLiveLedgerName::normalize($ledgerName);
        $normalizedMapped = TallyDealerMapping::normalizeName($ledgerName);
        if ($normalizedLive === '' && $normalizedMapped === '') {
            return null;
        }

        if ($normalizedMapped !== '') {
            $named = TallyDealerMapping::query()
                ->where('tally_ledger_name_normalized', $normalizedMapped)
                ->orderBy('id')
                ->get(['dealer_id']);
            if ($named->count() > 1) {
                return null;
            }
            if ($named->count() === 1) {
                return (int) $named->first()?->dealer_id;
            }
        }

        $guidMappedDealers = $this->guidMappedDealerIds();
        if ($normalizedLive === '') {
            return null;
        }

        $firmHits = [];
        foreach (Dealer::query()->get(['id', 'firm_name', 'status']) as $dealer) {
            if (TallyLiveLedgerName::normalize((string) $dealer->firm_name) !== $normalizedLive) {
                continue;
            }
            if (isset($guidMappedDealers[(int) $dealer->id])) {
                continue;
            }
            $firmHits[] = $dealer;
        }
        $active = array_values(array_filter($firmHits, static fn (Dealer $dealer): bool => (bool) $dealer->status));
        $candidates = count($active) === 1 ? $active : (count($firmHits) === 1 ? $firmHits : $active);
        if (count($candidates) === 1) {
            return (int) $candidates[0]->id;
        }

        $liveHits = [];
        foreach (DealerTallyLedger::query()->whereNotNull('live_tally_ledger_name')->get(['dealer_id', 'live_tally_ledger_name']) as $account) {
            $dealerId = (int) $account->dealer_id;
            if (isset($guidMappedDealers[$dealerId])) {
                continue;
            }
            if (TallyLiveLedgerName::normalize((string) $account->live_tally_ledger_name) !== $normalizedLive) {
                continue;
            }
            $liveHits[$dealerId] = true;
        }
        if (count($liveHits) === 1) {
            return (int) array_key_first($liveHits);
        }

        return null;
    }

    /**
     * Cached Tally ledger masters for the mapping dropdown. Key = GUID, label = ledger name.
     * Search matches the ledger name text as stored from Tally; it does not fuzzy-map similar names.
     *
     * @return array<string, string>
     */
    public function searchLedgers(string $search, int $limit = 5000): array
    {
        $limit = max(1, min($limit, 5000));
        $query = TallyConnectorLedger::query()
            ->whereNotNull('tally_ledger_guid')
            ->where('tally_ledger_guid', '!=', '');

        $latest = TallyConnectorLedger::query()->max('last_seen_at');
        if ($latest !== null) {
            $query->where('last_seen_at', $latest);
        }

        $term = trim($search);
        if ($term !== '') {
            $like = '%'.addcslashes(mb_strtolower($term, 'UTF-8'), '%_\\').'%';
            $query->whereRaw('LOWER(tally_ledger_name) like ?', [$like]);
            $query->orderByRaw('CASE WHEN LOWER(tally_ledger_name) = ? THEN 0 ELSE 1 END', [mb_strtolower($term, 'UTF-8')]);
        }

        return $query->orderBy('tally_ledger_name')
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (TallyConnectorLedger $ledger): array => [
                (string) $ledger->tally_ledger_guid => $this->ledgerLabel($ledger),
            ])
            ->all();
    }

    public function guidLedgerCount(): int
    {
        return TallyConnectorLedger::query()
            ->whereNotNull('tally_ledger_guid')
            ->where('tally_ledger_guid', '!=', '')
            ->count();
    }

    public function ledgerCatalogEmptyMessage(): ?string
    {
        if ($this->guidLedgerCount() > 0) {
            return null;
        }

        return app(TallyConnectorStatusService::class)->isConnected()
            ? 'Tally Ledgers Not Synced'
            : 'Tally Connector Offline';
    }

    public function ledgerLabel(?TallyConnectorLedger $ledger): string
    {
        if ($ledger === null) {
            return '';
        }

        return (string) $ledger->tally_ledger_name;
    }

    public function attachGuidIfMissing(Dealer $dealer, string $guid, string $ledgerName): ?TallyDealerMapping
    {
        $guid = self::normalizeGuid($guid);
        $hadGuid = $this->guidMappingFor($dealer) !== null;
        if ($guid === '') {
            return $this->mappingFor($dealer);
        }

        $existing = $this->guidMappingFor($dealer);
        if ($existing !== null) {
            $this->refreshMappedLedgerName($existing, $ledgerName);

            return $existing;
        }

        try {
            $mapping = $this->writeMapping($dealer, $guid, $ledgerName, overwrite: false, userId: null);
        } catch (TallyMappingException) {
            return $this->mappingFor($dealer);
        }

        if (! $hadGuid) {
            $this->requeueDealerCollections($dealer);
        }

        return $mapping;
    }

    public function assign(Dealer $dealer, string $guid, ?int $userId = null, bool $overwrite = false): TallyDealerMapping
    {
        $guid = self::normalizeGuid($guid);
        $catalog = TallyConnectorLedger::query()->where('tally_ledger_guid', $guid)->first();
        if ($guid === '' || $catalog === null) {
            throw new TallyMappingException('Select a Tally ledger from the latest Live Tally sync. Run Sync Live Tally Now if the list is empty.');
        }

        $mapping = $this->writeMapping(
            $dealer,
            $guid,
            (string) $catalog->tally_ledger_name,
            $overwrite,
            $userId,
        );
        $this->requeueDealerCollections($dealer);

        return $mapping;
    }

    public function remove(Dealer $dealer): void
    {
        DB::transaction(function () use ($dealer): void {
            $mapping = $this->guidMappingFor($dealer);
            if ($mapping === null) {
                return;
            }

            $mapping->tally_ledger_guid = null;
            $mapping->save();

            $account = $dealer->tallyLedger;
            if ($account !== null) {
                $account->fill([
                    'live_closing_balance' => null,
                    'live_closing_balance_type' => null,
                    'live_tally_ledger_name' => null,
                    'live_tally_ledger_guid' => null,
                    'live_synced_at' => null,
                ]);
                $account->save();
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $balances
     */
    public function upsertConnectorLedgers(array $balances, Carbon $seenAt): void
    {
        foreach ($balances as $row) {
            $name = TallyLiveLedgerName::canonical((string) ($row['tally_ledger_name'] ?? ''));
            $guid = self::normalizeGuid($row['tally_ledger_guid'] ?? '');
            if ($name === '') {
                continue;
            }

            $payload = [
                'tally_ledger_name' => $name,
                'tally_ledger_name_normalized' => TallyLiveLedgerName::normalize($name),
                'ledger_parent' => filled($row['ledger_parent'] ?? null) ? mb_substr(trim((string) $row['ledger_parent']), 0, 255) : null,
                'last_seen_at' => $seenAt,
            ];

            if ($guid !== '') {
                TallyConnectorLedger::query()->updateOrCreate(
                    ['tally_ledger_guid' => $guid],
                    $payload,
                );

                continue;
            }

            TallyConnectorLedger::query()
                ->whereNull('tally_ledger_guid')
                ->where('tally_ledger_name_normalized', $payload['tally_ledger_name_normalized'])
                ->update($payload);
        }
    }

    /**
     * Unique saved GUID for an exact Tally ledger name. Name is only used to
     * recover a missing payload GUID; it does not map ERP firm names.
     *
     * @return array{catalog: array<string, string>, mapping: array<string, string>}
     */
    public function uniqueGuidsIndexedByExactLedgerName(): array
    {
        return [
            'catalog' => $this->uniqueGuidsByName(
                TallyConnectorLedger::query()
                    ->whereNotNull('tally_ledger_guid')
                    ->where('tally_ledger_guid', '!=', '')
                    ->get(['tally_ledger_guid', 'tally_ledger_name_normalized'])
                    ->all(),
                'tally_ledger_name_normalized',
            ),
            'mapping' => $this->uniqueGuidsByName(
                TallyDealerMapping::query()
                    ->whereNotNull('tally_ledger_guid')
                    ->where('tally_ledger_guid', '!=', '')
                    ->get(['tally_ledger_guid', 'tally_ledger_name_normalized'])
                    ->all(),
                'tally_ledger_name_normalized',
            ),
        ];
    }

    /**
     * Resolve the Tally ledger GUID for a live-balance row without changing mappings.
     *
     * @param  array{catalog: array<string, string>, mapping: array<string, string>}  $lookups
     */
    public function resolveLiveIngestGuid(mixed $incomingGuid, string $ledgerName, array $lookups): string
    {
        $guid = self::normalizeGuid($incomingGuid);
        if ($guid !== '') {
            return $guid;
        }

        $catalogKey = TallyLiveLedgerName::normalize($ledgerName);
        if ($catalogKey !== '' && isset($lookups['catalog'][$catalogKey])) {
            return $lookups['catalog'][$catalogKey];
        }

        $mappingKey = TallyDealerMapping::normalizeName($ledgerName);
        if ($mappingKey !== '' && isset($lookups['mapping'][$mappingKey])) {
            return $lookups['mapping'][$mappingKey];
        }

        return '';
    }

    public function dealerIdForSavedGuid(string $guid, ?Collection $map = null): ?int
    {
        $guid = self::normalizeGuid($guid);
        if ($guid === '') {
            return null;
        }

        $map ??= $this->guidToDealerId();
        if ($map->has($guid)) {
            return (int) $map->get($guid);
        }

        $compact = str_replace('-', '', $guid);
        foreach ($map as $stored => $dealerId) {
            if (str_replace('-', '', (string) $stored) === $compact) {
                return (int) $dealerId;
            }
        }

        return null;
    }

    /**
     * @return Collection<string, int>
     */
    public function guidToDealerId(): Collection
    {
        return TallyDealerMapping::query()
            ->whereNotNull('tally_ledger_guid')
            ->where('tally_ledger_guid', '!=', '')
            ->orderBy('id')
            ->get(['dealer_id', 'tally_ledger_guid'])
            ->mapWithKeys(fn (TallyDealerMapping $mapping): array => [
                self::normalizeGuid($mapping->tally_ledger_guid) => (int) $mapping->dealer_id,
            ]);
    }

    /**
     * @return array<int, true>
     */
    public function guidMappedDealerIds(): array
    {
        $ids = [];
        foreach ($this->guidToDealerId() as $dealerId) {
            $ids[(int) $dealerId] = true;
        }

        return $ids;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, string>
     */
    private function uniqueGuidsByName(array $rows, string $nameAttribute): array
    {
        $unique = [];
        $ambiguous = [];

        foreach ($rows as $row) {
            $key = trim((string) ($row->{$nameAttribute} ?? ''));
            $guid = self::normalizeGuid($row->tally_ledger_guid ?? '');
            if ($key === '' || $guid === '') {
                continue;
            }
            if (isset($unique[$key]) && $unique[$key] !== $guid) {
                $ambiguous[$key] = true;

                continue;
            }
            $unique[$key] = $guid;
        }

        foreach ($ambiguous as $key => $ignored) {
            unset($unique[$key]);
        }

        return $unique;
    }

    private function writeMapping(
        Dealer $dealer,
        string $guid,
        string $ledgerName,
        bool $overwrite,
        ?int $userId,
    ): TallyDealerMapping {
        $guid = self::normalizeGuid($guid);
        $name = TallyLiveLedgerName::canonical($ledgerName);
        if ($guid === '' || $name === '') {
            throw new TallyMappingException('A Tally ledger GUID and ledger name are required.');
        }

        return DB::transaction(function () use ($dealer, $guid, $name, $overwrite, $userId): TallyDealerMapping {
            $owner = TallyDealerMapping::query()
                ->where('tally_ledger_guid', $guid)
                ->lockForUpdate()
                ->first();
            if ($owner !== null && (int) $owner->dealer_id !== (int) $dealer->id) {
                throw new TallyMappingException('That Tally ledger is already mapped to another ERP dealer.');
            }

            $mapping = TallyDealerMapping::query()
                ->where('dealer_id', $dealer->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($mapping !== null && self::normalizeGuid($mapping->tally_ledger_guid) !== '' && ! $overwrite) {
                if (self::normalizeGuid($mapping->tally_ledger_guid) !== $guid) {
                    throw new TallyMappingException('This dealer already has a saved Tally GUID mapping. Use Change Mapping to replace it.');
                }
                $this->refreshMappedLedgerName($mapping, $name);

                return $mapping->fresh() ?? $mapping;
            }

            $normalized = TallyDealerMapping::normalizeName($name);
            if ($normalized === '') {
                $normalized = TallyLiveLedgerName::normalize($name);
            }

            $payload = [
                'tally_ledger_name' => $name,
                'tally_ledger_name_normalized' => $normalized,
                'tally_ledger_guid' => $guid,
                'dealer_id' => $dealer->id,
            ];
            if ($userId !== null && ($mapping === null || $mapping->created_by === null)) {
                $payload['created_by'] = $userId;
            }

            try {
                if ($mapping === null) {
                    $mapping = TallyDealerMapping::query()->create($payload);
                } else {
                    $mapping->fill($payload);
                    $mapping->save();
                }
            } catch (UniqueConstraintViolationException $exception) {
                throw new TallyMappingException(
                    'That Tally ledger is already mapped to another ERP dealer.',
                    0,
                    $exception,
                );
            }

            $account = $dealer->tallyLedger;
            if ($overwrite && $account !== null) {
                $account->fill([
                    'live_closing_balance' => null,
                    'live_closing_balance_type' => null,
                    'live_tally_ledger_name' => $name,
                    'live_tally_ledger_guid' => $guid,
                    'live_synced_at' => null,
                ]);
                $account->save();
            }

            return $mapping->fresh() ?? $mapping;
        });
    }

    private function refreshMappedLedgerName(TallyDealerMapping $mapping, string $ledgerName): void
    {
        $name = TallyLiveLedgerName::canonical($ledgerName);
        if ($name === '' || $name === (string) $mapping->tally_ledger_name) {
            return;
        }

        $normalized = TallyDealerMapping::normalizeName($name);
        if ($normalized === '') {
            return;
        }

        try {
            $mapping->fill([
                'tally_ledger_name' => $name,
                'tally_ledger_name_normalized' => $normalized,
            ]);
            $mapping->save();
        } catch (UniqueConstraintViolationException) {
            // Keep the saved GUID. Name uniqueness must not remap the dealer.
        }
    }

    private function requeueDealerCollections(Dealer $dealer): void
    {
        app(TallyOutboundEnqueueService::class)->requeueReceivedCollectionsForDealer($dealer);
    }
}
