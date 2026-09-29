<?php

namespace App\Observers;

use App\Models\CreditNote;
use App\Services\Notifications\CreditNotePushNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreditNoteObserver
{
    public function created(CreditNote $creditNote): void
    {
        if ($creditNote->status !== CreditNote::STATUS_PENDING_APPROVAL) {
            return;
        }

        $creditNoteId = (int) $creditNote->id;

        // Keep FCM out of the DB transaction and out of the HTTP response path.
        DB::afterCommit(function () use ($creditNoteId): void {
            dispatch(function () use ($creditNoteId): void {
                try {
                    $note = CreditNote::query()->find($creditNoteId);
                    if ($note === null || $note->status !== CreditNote::STATUS_PENDING_APPROVAL) {
                        return;
                    }

                    app(CreditNotePushNotifier::class)->notifyCreated($note);
                } catch (Throwable $e) {
                    Log::warning('CreditNoteObserver notification error: '.$e->getMessage());
                }
            })->afterResponse();
        });
    }

    public function updated(CreditNote $creditNote): void
    {
        if (! $creditNote->wasChanged('status')) {
            return;
        }

        $creditNoteId = (int) $creditNote->id;
        $status = (string) $creditNote->status;

        DB::afterCommit(function () use ($creditNoteId, $status): void {
            dispatch(function () use ($creditNoteId, $status): void {
                try {
                    $fresh = CreditNote::query()->find($creditNoteId);
                    if ($fresh === null || $fresh->status !== $status) {
                        return;
                    }

                    $notifier = app(CreditNotePushNotifier::class);

                    match ($fresh->status) {
                        CreditNote::STATUS_APPROVED => $notifier->notifyApproved($fresh),
                        CreditNote::STATUS_PENDING_PRODUCTION_APPROVAL => $notifier->notifyPendingProduction($fresh),
                        CreditNote::STATUS_REJECTED => $notifier->notifyRejected($fresh),
                        CreditNote::STATUS_COMPLETED => $notifier->notifyCompleted($fresh),
                        default => null,
                    };
                } catch (Throwable $e) {
                    Log::warning('CreditNoteObserver notification error: '.$e->getMessage());
                }
            })->afterResponse();
        });
    }
}
