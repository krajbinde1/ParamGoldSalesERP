<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Dealers\DealerLedgerPostingService;
use App\Services\Notifications\OrderPushNotifier;
use App\Services\TallySync\TallyOutboundEnqueueService;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderObserver
{
    public function __construct(
        private readonly OrderPushNotifier $notifier,
        private readonly DealerLedgerPostingService $ledgerPosting,
        private readonly TallyOutboundEnqueueService $tallyOutbound,
    ) {}

    public function created(Order $order): void
    {
        $this->syncLedger($order);
        $this->queueTallySales($order);

        if ($order->status !== Order::STATUS_PENDING_APPROVAL) {
            return;
        }

        $this->safe(fn () => $this->notifier->notifyNewOrder($order->fresh() ?? $order));
    }

    public function updated(Order $order): void
    {
        if ($order->wasChanged('status') || $order->wasChanged('grand_total') || $order->wasChanged('dispatch_date')) {
            $this->syncLedger($order->fresh() ?? $order);
        }

        if ($order->wasChanged('status')) {
            $this->queueTallySales($order->fresh() ?? $order);
        }

        if (! $order->wasChanged('status')) {
            return;
        }

        $fresh = $order->fresh() ?? $order;

        $previous = $order->getOriginal('status');

        match ($fresh->status) {
            Order::STATUS_PENDING_APPROVAL => $this->safe(fn () => $this->notifier->notifyNewOrder($fresh)),
            Order::STATUS_APPROVED => $this->safe(function () use ($fresh, $previous): void {
                if ($previous === Order::STATUS_REVERTED_TO_MANAGER) {
                    $this->notifier->notifyReapproved($fresh);

                    return;
                }

                if ($previous === Order::STATUS_ON_HOLD) {
                    return;
                }

                $this->notifier->notifyApproved($fresh);
            }),
            Order::STATUS_ON_HOLD => $this->safe(fn () => $this->notifier->notifyOnHold($fresh)),
            Order::STATUS_REVERTED_TO_MANAGER => $this->safe(fn () => $this->notifier->notifyReverted($fresh)),
            Order::STATUS_PENDING_FOR_BILLING => $this->safe(fn () => $this->notifier->notifySentForBilling($fresh)),
            Order::STATUS_REJECTED => $this->safe(fn () => $this->notifier->notifyRejected($fresh)),
            Order::STATUS_BILLED => $this->safe(fn () => $this->notifier->notifyBilled($fresh)),
            Order::STATUS_DISPATCHED => $this->safe(fn () => $this->notifier->notifyDispatched($fresh)),
            default => null,
        };
    }

    private function syncLedger(Order $order): void
    {
        $this->ledgerPosting->syncDispatchedOrder($order);
    }

    private function queueTallySales(Order $order): void
    {
        try {
            $this->tallyOutbound->queueBilledOrder($order);
        } catch (Throwable $e) {
            Log::error('Tally outbound enqueue (order) failed: '.$e->getMessage(), [
                'order_id' => $order->id,
            ]);
        }
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
