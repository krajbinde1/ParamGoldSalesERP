<?php

namespace App\Services\TallySync;

use App\Models\Dealer;
use App\Models\DealerTallyLedger;
use App\Models\TallyDealerMapping;
use App\Models\TallyLiveSyncState;
use App\Services\TallyLedger\DealerTallyBalance;
use App\Services\TallyLedger\TallyLedgerConfig;
use App\Support\IndianCurrency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

                    $type = strtolower(trim((string) ($row['closing_balance_type'] ?? DealerTallyBalance::DEBIT)));
                    if (! in_array($type, [DealerTallyBalance::DEBIT, DealerTallyBalance::CREDIT], true)) {
                        $type = DealerTallyBalance::DEBIT;
                    }
                    $amount = round(abs((float) ($row['closing_balance'] ?? 0)), 2);
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
}
