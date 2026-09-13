<?php

namespace App\Actions\Dealers;

use App\Models\DealerTallyEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RemoveTallyLedgerEntry
{
    public function execute(DealerTallyEntry $entry, User $actor, string $reason): DealerTallyEntry
    {
        if (! $actor->isAdminUser() && ! $actor->isDirectorUser()) {
            throw new AuthorizationException('Only Admin or Director can remove a Tally ledger entry.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages([
                'reason' => ['Reason is required.'],
            ]);
        }

        return DB::transaction(function () use ($entry, $actor, $reason): DealerTallyEntry {
            $locked = DealerTallyEntry::query()
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'entry' => ['This Tally entry is no longer in the ledger.'],
                ]);
            }

            if (! DealerTallyEntry::isRemovableSource($locked->source)) {
                throw ValidationException::withMessages([
                    'entry' => ['Only Tally Import and Tally Journal entries can be removed from the ledger.'],
                ]);
            }

            $hadJournalIdentity = filled($locked->tally_voucher_guid) || filled($locked->tally_entry_key);
            $originalFingerprint = (string) $locked->fingerprint;
            $snapshot = $locked->auditSnapshot();

            $locked->fill([
                'removed_at' => Carbon::now('Asia/Kolkata'),
                'removed_by' => $actor->id,
                'removal_reason' => $reason,
                'original_snapshot' => $snapshot,
                'fingerprint' => DealerTallyEntry::makeRemovedFingerprint($originalFingerprint, (int) $locked->id),
                'tally_voucher_guid' => null,
                'tally_entry_key' => $hadJournalIdentity ? 'removed:'.$locked->id : $locked->tally_entry_key,
            ]);
            $locked->save();

            return $locked;
        });
    }
}
