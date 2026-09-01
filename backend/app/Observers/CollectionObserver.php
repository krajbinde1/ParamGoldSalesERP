<?php

namespace App\Observers;

use App\Models\Collection;
use App\Services\Dealers\DealerLedgerPostingService;
use App\Services\Notifications\CollectionPushNotifier;
use App\Services\TallySync\TallyOutboundEnqueueService;
use App\Services\WhatsApp\WhatsAppOutboundEnqueueService;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectionObserver
{
    public function __construct(
        private readonly CollectionPushNotifier $notifier,
        private readonly DealerLedgerPostingService $ledgerPosting,
        private readonly TallyOutboundEnqueueService $tallyOutbound,
        private readonly WhatsAppOutboundEnqueueService $whatsAppOutbound,
    ) {}

    public function created(Collection $collection): void
    {
        $fresh = $collection->fresh() ?? $collection;
        $this->syncLedger($fresh);
        $this->queueTallyReceipt($fresh);
        $this->queueWhatsAppReceipt($fresh);
        $this->safe(fn () => $this->notifier->notifyCreated($fresh));
    }

    public function updated(Collection $collection): void
    {
        if ($collection->wasChanged([
            'status',
            'amount',
            'collection_date',
            'dealer_id',
            'receipt_no',
        ])) {
            $this->syncLedger($collection->fresh() ?? $collection);
        }

        if ($collection->wasChanged('status')) {
            $this->queueTallyReceipt($collection->fresh() ?? $collection);
            $this->queueWhatsAppReceipt($collection->fresh() ?? $collection);
        }

        if (! $collection->wasChanged('status')) {
            return;
        }

        if ($collection->status === Collection::STATUS_RECEIVED) {
            $this->safe(fn () => $this->notifier->notifyReceived($collection->fresh() ?? $collection));
        }
    }

    public function deleted(Collection $collection): void
    {
        $this->ledgerPosting->removeCollectionLedgerEntry($collection);
    }

    private function syncLedger(Collection $collection): void
    {
        $this->ledgerPosting->syncReceivedCollection($collection);
    }

    private function queueTallyReceipt(Collection $collection): void
    {
        try {
            $this->tallyOutbound->queueReceivedCollection($collection);
        } catch (Throwable $e) {
            Log::error('Tally outbound enqueue (collection) failed: '.$e->getMessage(), [
                'collection_id' => $collection->id,
            ]);
        }
    }

    private function queueWhatsAppReceipt(Collection $collection): void
    {
        try {
            $this->whatsAppOutbound->queueReceivedCollection($collection);
        } catch (Throwable $e) {
            Log::error('WhatsApp outbound enqueue (collection) failed: '.$e->getMessage(), [
                'collection_id' => $collection->id,
            ]);
        }
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
