<?php

namespace App\Services\TallySync;

use App\Models\Dealer;
use App\Models\DealerTallyLedger;
use App\Models\TallyDealerMapping;
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
     * @return array{force_sync: bool, offline_after_seconds: int}
     */
    public function connectorPoll(): array
    {
        $state = TallyLiveSyncState::current();

        return [
            'force_sync' => $state->sync_requested_at !== null
                && ($state->last_balance_sync_at === null
                    || $state->sync_requested_at->gt($state->last_balance_sync_at)),
            'offline_after_seconds' => max(30, (int) config('tally.live_balance.offline_after_seconds', 120)),
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
     * @return array{matched: int, unmatched: int, tally_online: bool}
     */
    public function ingest(?string $connectorId, bool $tallyOnline, array $balances): array
    {
        $now = Carbon::now('Asia/Kolkata');
        $matched = 0;
        $unmatched = 0;

        DB::transaction(function () use ($connectorId, $tallyOnline, $balances, $now, &$matched, &$unmatched): void {
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
                $lookup = $this->dealerLookup();

                foreach ($balances as $row) {
                    $name = trim((string) ($row['tally_ledger_name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }

                    $interpreted = TallyClosingBalanceInterpreter::interpret($row);
                    $type = $interpreted['type'];
                    $amount = $interpreted['amount'];
                    $normalized = TallyDealerMapping::normalizeName($name);
                    $dealerId = $lookup[$normalized] ?? null;
                    if ($dealerId === null) {
                        $unmatched++;

                        continue;
                    }

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
                        'live_tally_ledger_name' => $name,
                        'live_synced_at' => $now,
                    ]);
                    $account->save();
                    $matched++;
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

                $state->last_matched_count = $matched;
            }

            $state->save();
        });

        return [
            'matched' => $matched,
            'unmatched' => $unmatched,
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
                'status_short' => 'Not mapped',
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offlineVerification(
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
        ];
    }

    /**
     * @return array<string, int>
     */
    private function dealerLookup(): array
    {
        $lookup = [];

        foreach (TallyDealerMapping::query()->get(['dealer_id', 'tally_ledger_name_normalized']) as $mapping) {
            $key = (string) $mapping->tally_ledger_name_normalized;
            if ($key !== '') {
                $lookup[$key] = (int) $mapping->dealer_id;
            }
        }

        $firmCounts = [];
        foreach (Dealer::query()->get(['id', 'firm_name']) as $dealer) {
            $key = TallyDealerMapping::normalizeName((string) $dealer->firm_name);
            if ($key === '' || isset($lookup[$key])) {
                continue;
            }
            $firmCounts[$key] = ($firmCounts[$key] ?? 0) + 1;
            if ($firmCounts[$key] === 1) {
                $lookup[$key] = (int) $dealer->id;
            } else {
                unset($lookup[$key]);
            }
        }

        return $lookup;
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
