<?php

namespace App\Observers;

use App\Models\CreditNote;
use App\Services\Notifications\CreditNotePushNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreditNoteObserver
{
    public function __construct(
        private readonly CreditNotePushNotifier $notifier,
    ) {}

    public function created(CreditNote $creditNote): void
    {
        if ($creditNote->status !== CreditNote::STATUS_PENDING_APPROVAL) {
            return;
        }

        $this->safe(fn () => $this->notifier->notifyCreated($creditNote->fresh() ?? $creditNote));
    }

    public function updated(CreditNote $creditNote): void
    {
        if (! $creditNote->wasChanged('status')) {
            return;
        }

        $fresh = $creditNote->fresh() ?? $creditNote;

        match ($fresh->status) {
            CreditNote::STATUS_APPROVED => $this->safe(fn () => $this->notifier->notifyApproved($fresh)),
            CreditNote::STATUS_REJECTED => $this->safe(fn () => $this->notifier->notifyRejected($fresh)),
            CreditNote::STATUS_COMPLETED => $this->safe(fn () => $this->notifier->notifyCompleted($fresh)),
            default => null,
        };
    }

    private function safe(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('CreditNoteObserver notification error: '.$e->getMessage());
        }
    }
}
