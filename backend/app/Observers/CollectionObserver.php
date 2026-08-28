<?php

namespace App\Observers;

use App\Models\Collection;
use App\Services\Dealers\DealerLedgerPostingService;
use App\Services\Notifications\CollectionPushNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectionObserver
{
    public function __construct(
        private readonly CollectionPushNotifier $notifier,
        private readonly DealerLedgerPostingService $ledgerPosting,
    ) {}

    public function created(Collection $collection): void
    {
        $this->syncLedger($collection->fresh() ?? $collection);
        $this->safe(fn () => $this->notifier->notifyCreated($collection->fresh() ?? $collection));
    }

    public function updated(Collection $collection): void
    {
        if ($collection->wasChanged('status') || $collection->wasChanged('amount') || $collection->wasChanged('collection_date')) {
            $this->syncLedger($collection->fresh() ?? $collection);
        }

        if (! $collection->wasChanged('status')) {
            return;
        }

        if ($collection->status === Collection::STATUS_RECEIVED) {
            $this->safe(fn () => $this->notifier->notifyReceived($collection->fresh() ?? $collection));
        }
    }

    private function syncLedger(Collection $collection): void
    {
        $this->ledgerPosting->syncReceivedCollection($collection);
    }

    private function safe(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('CollectionObserver notification error: '.$e->getMessage());
        }
    }
}
