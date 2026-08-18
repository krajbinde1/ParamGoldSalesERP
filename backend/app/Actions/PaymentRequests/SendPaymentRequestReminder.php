<?php

namespace App\Actions\PaymentRequests;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\Notifications\PaymentRequestPushNotifier;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SendPaymentRequestReminder
{
    public function __construct(
        private readonly PaymentRequestPushNotifier $notifier = new PaymentRequestPushNotifier,
    ) {}

    /**
     * Remind for one request (current stage approver).
     * Increments reminder_count only after FCM send succeeds.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function executeOne(PaymentRequest $paymentRequest, User $actor): PaymentRequest
    {
        if (! Gate::forUser($actor)->allows('remind', $paymentRequest)) {
            throw new AuthorizationException('You are not allowed to send reminders for this request.');
        }

        if (! $paymentRequest->isAwaitingApproval()) {
            throw ValidationException::withMessages([
                'status' => ['Reminders can only be sent while Director approval is pending.'],
            ]);
        }

        $nextCount = ((int) $paymentRequest->reminder_count) + 1;
        $result = $this->notifier->notifyReminderForRequest($paymentRequest, $nextCount);

        if (! $result['sent']) {
            throw ValidationException::withMessages([
                'reminder' => ['Unable to send reminder. Please try again.'],
            ]);
        }

        $now = Carbon::now('Asia/Kolkata');
        $paymentRequest->update([
            'reminder_count' => $nextCount,
            'last_reminded_at' => $now,
            'last_reminded_by' => $actor->id,
        ]);

        return $paymentRequest->fresh() ?? $paymentRequest;
    }

    /**
     * Remind all requests pending with the same current approver as the given request,
     * or all pending for a given stage when $status is provided.
     *
     * reminder_count is incremented only for requests whose FCM send succeeded.
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

        $now = Carbon::now('Asia/Kolkata');
        $updatedIds = [];

        foreach ($requests as $request) {
            $nextCount = ((int) $request->reminder_count) + 1;
            $result = $this->notifier->notifyReminderForRequest($request, $nextCount);
            if (! $result['sent']) {
                continue;
            }

            $request->update([
                'reminder_count' => $nextCount,
                'last_reminded_at' => $now,
                'last_reminded_by' => $actor->id,
            ]);
            $updatedIds[] = $request->id;
        }

        if ($updatedIds === []) {
            throw ValidationException::withMessages([
                'reminder' => ['Unable to send reminder. Please try again.'],
            ]);
        }

        return PaymentRequest::query()
            ->whereIn('id', $updatedIds)
            ->orderBy('id')
            ->get();
    }
}
