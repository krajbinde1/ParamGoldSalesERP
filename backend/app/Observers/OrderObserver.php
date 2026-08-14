<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Notifications\OrderPushNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderObserver
{
    public function __construct(
        private readonly OrderPushNotifier $notifier,
    ) {}

    public function created(Order $order): void
    {
        if ($order->status !== Order::STATUS_PENDING_APPROVAL) {
            return;
        }

        $this->safe(fn () => $this->notifier->notifyNewOrder($order->fresh() ?? $order));
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        $fresh = $order->fresh() ?? $order;

        match ($fresh->status) {
            // Filament draft → pending_approval (and any other transition into pending approval).
            // Dedupe prevents a second push if created() already notified.
            Order::STATUS_PENDING_APPROVAL => $this->safe(fn () => $this->notifier->notifyNewOrder($fresh)),
            Order::STATUS_APPROVED => $this->safe(fn () => $this->notifier->notifyApproved($fresh)),
            Order::STATUS_PENDING_FOR_BILLING => $this->safe(fn () => $this->notifier->notifySentForBilling($fresh)),
            Order::STATUS_REJECTED => $this->safe(fn () => $this->notifier->notifyRejected($fresh)),
            Order::STATUS_BILLED => $this->safe(fn () => $this->notifier->notifyBilled($fresh)),
            Order::STATUS_DISPATCHED => $this->safe(fn () => $this->notifier->notifyDispatched($fresh)),
            default => null,
        };
    }

    private function safe(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('OrderObserver notification error: '.$e->getMessage());
        }
    }
}
