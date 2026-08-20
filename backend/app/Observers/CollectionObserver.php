<?php

namespace App\Observers;

use App\Models\Collection;
use App\Services\Notifications\CollectionPushNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectionObserver
{
    public function __construct(
        private readonly CollectionPushNotifier $notifier,
    ) {}

    public function created(Collection $collection): void
    {
        $this->safe(fn () => $this->notifier->notifyCreated($collection->fresh() ?? $collection));
    }

    public function updated(Collection $collection): void
    {
        if (! $collection->wasChanged('status')) {
            return;
        }

        if ($collection->status === Collection::STATUS_RECEIVED) {
            $this->safe(fn () => $this->notifier->notifyReceived($collection->fresh() ?? $collection));
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
