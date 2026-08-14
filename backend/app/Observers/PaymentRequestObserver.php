<?php

namespace App\Observers;

use App\Models\PaymentRequest;
use App\Services\Notifications\PaymentRequestPushNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentRequestObserver
{
    public function __construct(
        private readonly PaymentRequestPushNotifier $notifier,
    ) {}

    public function created(PaymentRequest $paymentRequest): void
    {
        if ($paymentRequest->status !== PaymentRequest::STATUS_PENDING_FIRST) {
            return;
        }

        $this->safe(fn () => $this->notifier->notifyCreated($paymentRequest->fresh() ?? $paymentRequest));
    }

    public function updated(PaymentRequest $paymentRequest): void
    {
        if (! $paymentRequest->wasChanged('status')) {
            return;
        }

        $fresh = $paymentRequest->fresh() ?? $paymentRequest;

        match ($fresh->status) {
            PaymentRequest::STATUS_PENDING_SECOND => $this->safe(
                fn () => $this->notifier->notifyFirstApproved($fresh)
            ),
            PaymentRequest::STATUS_APPROVED_FOR_PAYMENT => $this->safe(
                fn () => $this->notifier->notifyFinalApproved($fresh)
            ),
            PaymentRequest::STATUS_REJECTED_FIRST => $this->safe(
                fn () => $this->notifier->notifyRejectedByFirst($fresh)
            ),
            PaymentRequest::STATUS_REJECTED_SECOND => $this->safe(
                fn () => $this->notifier->notifyRejectedBySecond($fresh)
            ),
            PaymentRequest::STATUS_PAYMENT_DONE => $this->safe(
                fn () => $this->notifier->notifyPaymentDone($fresh)
            ),
            default => null,
        };
    }

    private function safe(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('PaymentRequestObserver notification error: '.$e->getMessage());
        }
    }
}
