<?php

namespace App\Services\TallySync;

use App\Models\TallyOutboundVoucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TallyConnectorService
{
    /**
     * @return list<TallyOutboundVoucher>
     */
    public function pending(int $limit): array
    {
        $limit = max(1, min(
            $limit,
            (int) config('tally.connector.pending_limit_max', 50),
        ));

        return TallyOutboundVoucher::query()
            ->claimable()
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function claim(TallyOutboundVoucher $voucher, ?string $connectorId): TallyOutboundVoucher
    {
        return DB::transaction(function () use ($voucher, $connectorId): TallyOutboundVoucher {
            /** @var TallyOutboundVoucher $locked */
            $locked = TallyOutboundVoucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($locked->isSynced()) {
                throw ValidationException::withMessages([
                    'status' => ['This voucher is already synced to Tally.'],
                ]);
            }

            if ($locked->isFailed()) {
                throw ValidationException::withMessages([
                    'status' => [$locked->last_error ?: 'This voucher is not ready to send to Tally.'],
                ]);
            }

            if ($locked->hasBlockingClaim($connectorId)) {
                throw ValidationException::withMessages([
                    'status' => ['This voucher is currently claimed by another connector.'],
                ]);
            }

            $ttl = max(30, (int) config('tally.connector.claim_ttl_seconds', 120));
            $now = Carbon::now();

            $locked->update([
                'status' => TallyOutboundVoucher::STATUS_CLAIMED,
                'claimed_at' => $now,
                'claimed_until' => $now->copy()->addSeconds($ttl),
                'claimed_by' => filled($connectorId) ? $connectorId : $locked->claimed_by,
                'attempts' => $locked->attempts + 1,
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    public function markSynced(
        TallyOutboundVoucher $voucher,
        ?string $tallyVoucherNo,
        ?string $tallyMasterId,
    ): TallyOutboundVoucher {
        return DB::transaction(function () use ($voucher, $tallyVoucherNo, $tallyMasterId): TallyOutboundVoucher {
            /** @var TallyOutboundVoucher $locked */
            $locked = TallyOutboundVoucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($locked->isSynced()) {
                return $locked;
            }

            if ($locked->isFailed()) {
                throw ValidationException::withMessages([
                    'status' => [$locked->last_error ?: 'This voucher is not ready to send to Tally.'],
                ]);
            }

            $locked->update([
                'status' => TallyOutboundVoucher::STATUS_SYNCED,
                'tally_voucher_no' => filled($tallyVoucherNo) ? trim((string) $tallyVoucherNo) : $locked->tally_voucher_no,
                'tally_master_id' => filled($tallyMasterId) ? trim((string) $tallyMasterId) : $locked->tally_master_id,
                'synced_at' => Carbon::now(),
                'last_error' => null,
                'claimed_until' => null,
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    public function markFailed(TallyOutboundVoucher $voucher, string $error): TallyOutboundVoucher
    {
        $error = trim($error);

        return DB::transaction(function () use ($voucher, $error): TallyOutboundVoucher {
            /** @var TallyOutboundVoucher $locked */
            $locked = TallyOutboundVoucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($locked->isSynced()) {
                throw ValidationException::withMessages([
                    'status' => ['This voucher is already synced to Tally.'],
                ]);
            }

            $locked->update([
                'status' => TallyOutboundVoucher::STATUS_FAILED,
                'last_error' => $error,
                'claimed_until' => null,
            ]);

            return $locked->fresh() ?? $locked;
        });
    }
}
