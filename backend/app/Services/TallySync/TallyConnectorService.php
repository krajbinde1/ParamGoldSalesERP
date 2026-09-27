<?php

namespace App\Services\TallySync;

use App\Models\Collection;
use App\Models\TallyOutboundVoucher;
use App\Services\Dealers\DealerSalesLedgerReconciler;
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

        $enqueue = app(TallyOutboundEnqueueService::class);
        $enqueue->skipIneligibleQueuedReceipts();
        $enqueue->rewriteUnsyncedReceiptDebitLedgers();

        return TallyOutboundVoucher::query()
            ->claimable()
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Read-only lookup for tracing. Never rewrites payloads or statuses.
     *
     * @return list<TallyOutboundVoucher>
     */
    public function lookupReceipts(
        ?string $tallyVoucherNo,
        ?string $erpReference,
        ?int $sourceId,
        ?int $id,
    ): array {
        $query = TallyOutboundVoucher::query()
            ->where('voucher_type', TallyOutboundVoucher::VOUCHER_RECEIPT);

        if ($id !== null) {
            $query->whereKey($id);
        }

        if ($sourceId !== null) {
            $query->where('source_type', TallyOutboundVoucher::SOURCE_COLLECTION)
                ->where('source_id', $sourceId);
        }

        if (filled($erpReference)) {
            $query->where('erp_reference', $erpReference);
        }

        if (filled($tallyVoucherNo)) {
            $no = trim((string) $tallyVoucherNo);
            $query->where(function ($inner) use ($no): void {
                $inner->where('tally_voucher_no', $no)
                    ->orWhere('erp_reference', $no)
                    ->orWhere('erp_reference', 'ERP-COL-'.$no)
                    ->orWhere('payload->collection->receipt_no', $no);
            });
        }

        return $query->orderByDesc('id')->limit(20)->get()->all();
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

            if ($locked->isFailed() || $locked->isSkipped()) {
                throw ValidationException::withMessages([
                    'status' => [$locked->last_error ?: 'This voucher is not ready to send to Tally.'],
                ]);
            }

            if ($locked->source_type === TallyOutboundVoucher::SOURCE_COLLECTION
                && ! $this->collectionReceiptIsSendable($locked)) {
                app(TallyOutboundEnqueueService::class)->skipIneligibleQueuedReceipts();

                throw ValidationException::withMessages([
                    'status' => [TallyOutboundEnqueueService::ERROR_HISTORICAL_RECEIPT],
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

            if ($locked->isFailed() || $locked->isSkipped()) {
                throw ValidationException::withMessages([
                    'status' => [$locked->last_error ?: 'This voucher is not ready to send to Tally.'],
                ]);
            }

            if ($locked->source_type === TallyOutboundVoucher::SOURCE_COLLECTION
                && ! $this->collectionReceiptIsSendable($locked)) {
                throw ValidationException::withMessages([
                    'status' => [TallyOutboundEnqueueService::ERROR_HISTORICAL_RECEIPT],
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

            $fresh = $locked->fresh() ?? $locked;
            app(DealerSalesLedgerReconciler::class)->stampOutboundSalesSync($fresh);

            return $fresh;
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

    private function collectionReceiptIsSendable(TallyOutboundVoucher $voucher): bool
    {
        $collection = Collection::query()->withTrashed()->find($voucher->source_id);

        return $collection instanceof Collection
            && app(TallyOutboundEnqueueService::class)->isEligibleForTallyReceipt($collection);
    }
}
