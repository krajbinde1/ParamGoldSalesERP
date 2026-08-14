<?php

namespace App\Actions\PaymentRequests;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\Notifications\PaymentRequestPushNotifier;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SendPaymentRequestReminder
{
    public function __construct(
        private readonly PaymentRequestPushNotifier $notifier = new PaymentRequestPushNotifier,
    ) {}

    /**
     * Remind for one request (current stage approver).
     */
    public function executeOne(PaymentRequest $paymentRequest, User $actor): PaymentRequest
    {
        if (! Gate::forUser($actor)->allows('remind', $paymentRequest)) {
            throw new AuthorizationException('You are not allowed to send reminders for this request.');
        }

        $this->bumpAndNotify(collect([$paymentRequest]), $actor);

        return $paymentRequest->fresh() ?? $paymentRequest;
    }

    /**
     * Remind all requests pending with the same current approver as the given request,
     * or all pending for a given stage when $status is provided.
     *
     * @return Collection<int, PaymentRequest>
     */
    public function executeForApproverQueue(
        User $actor,
        ?PaymentRequest $seed = null,
        ?string $status = null,
    ): Collection {
        if (! Gate::forUser($actor)->allows('remindPending', PaymentRequest::class)) {
            throw new AuthorizationException('You are not allowed to send payment request reminders.');
        }

        $targetStatus = $status;
        if ($targetStatus === null && $seed !== null) {
            $targetStatus = $seed->status;
        }

        if (! in_array($targetStatus, [
            PaymentRequest::STATUS_PENDING_FIRST,
            PaymentRequest::STATUS_PENDING_SECOND,
        ], true)) {
            return collect();
        }

        $requests = PaymentRequest::query()
            ->where('status', $targetStatus)
            ->orderBy('id')
            ->get();

        if ($requests->isEmpty()) {
            return $requests;
        }

        $this->bumpAndNotify($requests, $actor);

        return $requests;
    }

    /**
     * @param  Collection<int, PaymentRequest>  $requests
     */
    private function bumpAndNotify(Collection $requests, User $actor): void
    {
        if ($requests->isEmpty()) {
            return;
        }

        $now = Carbon::now('Asia/Kolkata');

        DB::transaction(function () use ($requests, $actor, $now): void {
            foreach ($requests as $request) {
                $request->update([
                    'reminder_count' => ((int) $request->reminder_count) + 1,
                    'last_reminded_at' => $now,
                    'last_reminded_by' => $actor->id,
                ]);
            }
        });

        $fresh = PaymentRequest::query()
            ->whereIn('id', $requests->pluck('id')->all())
            ->get();

        $this->notifier->notifyReminder($fresh);
    }
}
